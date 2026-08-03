<?php

namespace App\Console\Commands;

use App\Models\ComplianceCheck;
use App\Models\Draft;
use App\Services\Imagery\CustomisedPostScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovery for recurring customised-post drafts stranded by the pre-2026-08-03
 * verdict-laundering bug in CustomisedPostScheduler::cloneComplianceVerdict().
 *
 * ── What went wrong ──────────────────────────────────────────────────────────
 *
 * A recurring customised post runs compliance ONCE, on the first occurrence, and
 * clones that verdict to the byte-identical followers. When the first occurrence
 * FAILED, the old code cloned 'awaiting_approval' instead of the real
 * 'compliance_failed'. Two consequences, both bad:
 *
 *   - the followers were written with ZERO compliance_check rows, so the Drafts
 *     page showed them held with no reason at all;
 *   - 'awaiting_approval' is a human gate, and a GREEN-lane brand has no human
 *     approver by design — so the whole series went quiet instead of surfacing
 *     as broken. Brand #1 lost seven weeks this way (2026-07-17 → 2026-08-03,
 *     306 drafts, 52 calendar days × 6 platforms).
 *
 * ── What this command does ───────────────────────────────────────────────────
 *
 * It finds those stranded followers and gives them the honest verdict their
 * series head already earned, plus the head's check rows so the reason travels.
 * The copy is byte-identical to the head, so the head's verdict IS the correct
 * verdict — no LLM re-run needed, no cost.
 *
 * ── What it deliberately will NOT do ─────────────────────────────────────────
 *
 * It only ever writes 'compliance_failed', and only when the series head itself
 * failed. It never approves, never schedules, never publishes. That is not a
 * limitation, it is the whole safety property:
 *
 *   - the bug ONLY mis-set followers when the head failed. A head that passed
 *     cloned correctly, so there is nothing to repair in that direction; and
 *   - a command that could clone 'approved' across a 52-occurrence series would
 *     be a one-keystroke mass-publish. Approval stays a human/ComplianceAgent
 *     decision, exactly as the autonomy contract requires.
 *
 * Drafts whose head cannot be identified, or whose head carries no check rows,
 * are reported and LEFT ALONE. We never guess a verdict we did not observe.
 *
 * Idempotent: a repaired draft is no longer 'awaiting_approval'-with-no-checks,
 * so it drops out of the query. Safe to re-run. Defaults to a dry run.
 */
class DraftsReverdictStrandedCustomised extends Command
{
    protected $signature = 'drafts:reverdict-stranded-customised
                            {--brand= : limit to a single brand id}
                            {--apply : actually write; omit for a dry run}
                            {--limit=2000 : safety ceiling on drafts examined}';

    protected $description = 'Give stranded recurring customised-post drafts the honest compliance verdict their series head earned (never approves).';

    /** The provenance stamp CustomisedPostScheduler writes on every draft it creates. */
    private const CUSTOMISED_PROMPT_VERSION = 'customised-post.v1';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $onlyBrand = $this->option('brand') ? (int) $this->option('brand') : null;

        // The stranded signature: a customised draft parked at the human gate
        // that compliance never actually examined (no check rows at all).
        $query = Draft::query()
            ->where('status', 'awaiting_approval')
            ->where('prompt_version', self::CUSTOMISED_PROMPT_VERSION)
            ->whereDoesntHave('complianceChecks');

        if ($onlyBrand) {
            $query->where('brand_id', $onlyBrand);
        }

        $stranded = $query->orderBy('id')->limit($limit)->get();

        if ($stranded->isEmpty()) {
            $this->info('No stranded customised drafts found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Found %d stranded customised draft(s)%s.%s',
            $stranded->count(),
            $onlyBrand ? " for brand #{$onlyBrand}" : '',
            $apply ? '' : ' (DRY RUN — pass --apply to write)',
        ));

        $totals = [
            'examined' => $stranded->count(),
            'reverdicted_failed' => 0,
            'left_head_passed' => 0,
            'left_no_head' => 0,
            'left_head_unchecked' => 0,
            'errors' => 0,
        ];

        // Cache the head verdict per (brand_asset_id, platform) — one series head
        // serves every follower on that platform.
        $headCache = [];

        foreach ($stranded as $draft) {
            $assetId = $this->assetIdFor($draft);
            if ($assetId === null) {
                $totals['left_no_head']++;
                $this->line("  draft#{$draft->id}: no brand_asset_id in prompt_inputs — left alone.");
                continue;
            }

            $key = $assetId.'|'.$draft->platform;
            if (! array_key_exists($key, $headCache)) {
                $headCache[$key] = $this->resolveHead($assetId, (string) $draft->platform, (int) $draft->brand_id);
            }
            $head = $headCache[$key];

            if (! $head) {
                $totals['left_no_head']++;
                $this->line("  draft#{$draft->id} ({$draft->platform}): no series head found for asset #{$assetId} — left alone.");
                continue;
            }

            $checks = $head->complianceChecks()->get();
            if ($checks->isEmpty()) {
                $totals['left_head_unchecked']++;
                $this->line("  draft#{$draft->id} ({$draft->platform}): series head #{$head->id} has no check rows — left alone.");
                continue;
            }

            // The ONLY verdict this command propagates. Anything else (including
            // a head that passed) is already correct or is not ours to decide.
            $verdict = CustomisedPostScheduler::inheritedStatusFor($head->status);
            if ($verdict !== 'compliance_failed') {
                $totals['left_head_passed']++;
                $this->line("  draft#{$draft->id} ({$draft->platform}): head #{$head->id} is '{$head->status}' — not a failure, left alone.");
                continue;
            }

            if (! $apply) {
                $totals['reverdicted_failed']++;
                $reason = (string) ($checks->firstWhere('result', 'fail')?->reason ?? 'see head');
                $this->line(sprintf(
                    '  draft#%d (%s): would set compliance_failed + %d check row(s) — %s',
                    $draft->id, $draft->platform, $checks->count(), mb_substr($reason, 0, 90),
                ));
                continue;
            }

            try {
                $draft->update(['status' => 'compliance_failed']);

                foreach ($checks as $c) {
                    ComplianceCheck::create([
                        'draft_id' => $draft->id,
                        'brand_id' => $draft->brand_id,
                        'check_type' => $c->check_type,
                        'score' => $c->score,
                        'threshold' => $c->threshold,
                        'result' => $c->result,
                        'reason' => $c->reason,
                        'details' => $c->details,
                        'model_id' => $c->model_id,
                        'latency_ms' => $c->latency_ms,
                        'checked_at' => $c->checked_at,
                    ]);
                }

                $totals['reverdicted_failed']++;
            } catch (\Throwable $e) {
                $totals['errors']++;
                $this->error("  draft#{$draft->id}: {$e->getMessage()}");
                Log::warning('DraftsReverdictStrandedCustomised: could not re-verdict draft', [
                    'draft_id' => $draft->id,
                    'error' => substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $this->line('');
        $this->line('--- re-verdict summary'.($apply ? '' : ' (DRY RUN)').' ---');
        foreach ($totals as $k => $v) {
            $this->line(str_pad($k, 22).": {$v}");
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Nothing was written. Re-run with --apply to commit.');
        } else {
            Log::info('DraftsReverdictStrandedCustomised run complete', $totals);
        }

        return self::SUCCESS;
    }

    /** The BrandAsset id the scheduler stamped into prompt_inputs at draft time. */
    private function assetIdFor(Draft $draft): ?int
    {
        $inputs = $draft->prompt_inputs;
        if (! is_array($inputs)) {
            return null;
        }

        $id = $inputs['brand_asset_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The series head for one (asset, platform): the draft on the calendar entry
     * the BrandAsset's customised_calendar_entry_id FK anchors to. That FK is set
     * to the FIRST occurrence by CustomisedPostScheduler::schedule(), which is
     * also the only occurrence compliance actually ran on.
     */
    private function resolveHead(int $assetId, string $platform, int $brandId): ?Draft
    {
        $headEntryId = \App\Models\BrandAsset::whereKey($assetId)
            ->where('brand_id', $brandId) // tenant safety: never cross a brand boundary
            ->value('customised_calendar_entry_id');

        if (! $headEntryId) {
            return null;
        }

        return Draft::where('calendar_entry_id', $headEntryId)
            ->where('brand_id', $brandId)
            ->where('platform', $platform)
            ->where('prompt_version', self::CUSTOMISED_PROMPT_VERSION)
            ->orderBy('id')
            ->first();
    }
}
