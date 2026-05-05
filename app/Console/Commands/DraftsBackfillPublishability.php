<?php

namespace App\Console\Commands;

use App\Agents\ComplianceAgent;
use App\Models\ComplianceCheck;
use App\Models\Draft;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill for the platform_publishability gap.
 *
 * Background: the `platform_publishability` compliance check was added in
 * commit ae7f72f (2026-05-05). Drafts created BEFORE that commit were scored
 * by the older 5-check gate, which couldn't detect missing media on
 * media-required platforms (YouTube, Instagram, TikTok, Pinterest). Some of
 * those drafts auto-approved on the green lane and got queued — when their
 * ScheduledPost dispatches, Blotato either rejects with HTTP 422 or, in the
 * YouTube case, accepts the createPost and crashes its renderer with
 * `TypeError: Failed to parse URL from undefined` (failed/450758 et al).
 *
 * What this does:
 *   1. Finds every Draft whose platform mandates media AND has no
 *      `platform_publishability` ComplianceCheck row AND is in a state that
 *      could still publish (approved / scheduled / awaiting_approval).
 *   2. Re-runs ComplianceAgent on each — adds the missing check; if media is
 *      truly absent, the draft flips to `compliance_failed` and the existing
 *      `drafts:redraft-failed` cron picks it up to regenerate media.
 *   3. For drafts that flip to compliance_failed, cancels any live
 *      ScheduledPost rows (queued / submitting / submitted-without-id) so the
 *      next minute's `posts:dispatch-due` doesn't ship them while redraft is
 *      in flight. Cancellation is reversible: when the redrafted draft
 *      re-approves, `posts:auto-schedule-approved` creates a fresh SP row.
 *
 * Idempotent — safe to re-run; drafts already carrying a publishability check
 * are skipped.
 */
class DraftsBackfillPublishability extends Command
{
    protected $signature = 'drafts:backfill-publishability
                            {--limit=200 : max drafts to re-gate per run}
                            {--platforms= : comma-separated platforms to limit to (default: all media-required)}
                            {--dry-run : show what would be re-gated, do not write}';

    protected $description = 'Re-run ComplianceAgent on drafts created before the platform_publishability gate to surface missing-media failures.';

    /** Platforms where Blotato/native APIs require at least one media item. */
    private const MEDIA_REQUIRED_PLATFORMS = ['youtube', 'instagram', 'tiktok', 'pinterest'];

    /**
     * Draft statuses that can still cause a publish. We deliberately leave
     * `compliance_failed` and `published` alone — failed already routes to
     * the redraft loop, published is done.
     */
    private const REGATE_STATUSES = ['approved', 'scheduled', 'awaiting_approval'];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        $platformsOpt = trim((string) $this->option('platforms'));
        $platforms = $platformsOpt === ''
            ? self::MEDIA_REQUIRED_PLATFORMS
            : array_values(array_filter(array_map('trim', explode(',', strtolower($platformsOpt)))));

        // Find draft IDs that already carry a publishability check (so we
        // know which ones to SKIP). Single subquery — cheaper than per-draft
        // exists() in the loop.
        $alreadyGatedIds = ComplianceCheck::query()
            ->where('check_type', 'platform_publishability')
            ->select('draft_id')
            ->distinct()
            ->pluck('draft_id');

        $drafts = Draft::query()
            ->with('brand:id,workspace_id')
            ->whereIn('platform', $platforms)
            ->whereIn('status', self::REGATE_STATUSES)
            ->whereNotIn('id', $alreadyGatedIds)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('No drafts need backfill — all media-required drafts already gated.');
            return self::SUCCESS;
        }

        $this->info("Found {$drafts->count()} draft(s) to re-gate.");

        $stats = [
            'regated' => 0,
            'flipped_failed' => 0,
            'still_passing' => 0,
            'sp_cancelled' => 0,
            'errors' => 0,
        ];

        foreach ($drafts as $draft) {
            $brand = $draft->brand;
            if (! $brand) {
                $this->warn("Draft #{$draft->id}: brand missing; skipping.");
                $stats['errors']++;
                continue;
            }

            $line = sprintf(
                'draft #%d (%s) brand=%d status=%s lane=%s',
                $draft->id, $draft->platform, $brand->id, $draft->status, $draft->lane,
            );

            if ($dry) {
                $this->line('[dry] would re-gate '.$line);
                continue;
            }

            try {
                $result = app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
            } catch (\Throwable $e) {
                $this->warn("Draft #{$draft->id}: ComplianceAgent crashed — ".$e->getMessage());
                $stats['errors']++;
                continue;
            }

            $stats['regated']++;

            // Reload to see the new status set by ComplianceAgent.
            $draft->refresh();

            if ($draft->status === 'compliance_failed') {
                $stats['flipped_failed']++;
                $cancelled = $this->cancelLiveScheduledPosts($draft);
                $stats['sp_cancelled'] += $cancelled;
                $this->warn(sprintf(
                    '%s → compliance_failed (cancelled %d live SP%s; redraft loop will regen media).',
                    $line, $cancelled, $cancelled === 1 ? '' : 's',
                ));
            } else {
                $stats['still_passing']++;
                $this->info($line.' → still '.$draft->status.' (publishability check passed; media is present).');
            }
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line("re-gated:                {$stats['regated']}");
        $this->line("flipped to failed:       {$stats['flipped_failed']}");
        $this->line("still passing:           {$stats['still_passing']}");
        $this->line("ScheduledPosts cancelled: {$stats['sp_cancelled']}");
        $this->line("errors:                  {$stats['errors']}");

        if ($stats['flipped_failed'] > 0) {
            $this->line('');
            $this->info('Next: `drafts:redraft-failed` cron (every 5 min) will regenerate media via Designer/Video.');
        }

        return self::SUCCESS;
    }

    /**
     * Cancel ScheduledPost rows linked to a now-failed draft so the publish
     * cron won't ship them while redraft is in flight. We only touch rows
     * that haven't reached Blotato yet (queued) or are stuck waiting for a
     * platform_post_id (submitted without id) — anything already published
     * or with a real platform_post_id is left alone (it's live; can't be
     * unpublished from here).
     *
     * @return int rows cancelled
     */
    private function cancelLiveScheduledPosts(Draft $draft): int
    {
        return DB::transaction(function () use ($draft) {
            $count = 0;

            $rows = ScheduledPost::where('draft_id', $draft->id)
                ->whereIn('status', ['queued', 'submitting'])
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $row->update([
                    'status' => 'cancelled',
                    'last_error' => 'Cancelled by drafts:backfill-publishability — draft re-flipped to compliance_failed (missing media); redraft loop will regenerate.',
                ]);
                $count++;
            }

            return $count;
        });
    }
}
