<?php

namespace App\Console\Commands;

use App\Agents\ComplianceAgent;
use App\Models\Draft;
use App\Services\Imagery\RenderedMediaInspector;
use Illuminate\Console\Command;

/**
 * Finds drafts whose attached image is a BLANK canvas — the defect that put six
 * empty posts on six live accounts on 2026-07-29/30 (scheduled_posts #541-#546).
 *
 * Compliance check 9 (media_quality) now catches this before approval, but it
 * only runs when the gate runs. Anything already approved, scheduled or
 * published was never pixel-inspected, so this command sweeps the back catalogue
 * using the same two layers in the same order:
 *
 *   1. render contract — branding_payload.render_contract proves the asset is a
 *      text-free background that never got its text. Free, exact, no download.
 *   2. pixel forensics — RenderedMediaInspector, for the (many) assets generated
 *      before the contract existed. One fetch per draft.
 *
 * READ-ONLY. It reports; it does not regenerate, retract, or unpublish anything.
 * Deciding what to do about a post that is already live is the operator's call —
 * for unpublished drafts, `drafts:regenerate-image` is the follow-up.
 */
class MediaAuditBlank extends Command
{
    protected $signature = 'media:audit-blank
                            {--brand= : Limit to one brand id}
                            {--status= : CSV of draft statuses to scan (default: all)}
                            {--since= : Only drafts created on/after this date (Y-m-d)}
                            {--limit=200 : Max drafts to inspect in one run}
                            {--contract-only : Skip the network pass; report only contract-proven blanks}';

    protected $description = 'Report drafts whose attached image is a blank/empty canvas.';

    public function handle(): int
    {
        $query = Draft::query()
            ->whereNotNull('asset_url')
            ->where('asset_url', '!=', '')
            ->orderByDesc('id');

        if ($brandId = $this->option('brand')) {
            $query->where('brand_id', (int) $brandId);
        }
        if ($statuses = $this->option('status')) {
            $query->whereIn('status', array_filter(array_map('trim', explode(',', (string) $statuses))));
        }
        if ($since = $this->option('since')) {
            $query->whereDate('created_at', '>=', $since);
        }

        $drafts = $query->limit((int) $this->option('limit'))->get();
        if ($drafts->isEmpty()) {
            $this->info('No drafts with media matched the filters.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Inspecting %d draft(s)…', $drafts->count()));

        $inspector = app(RenderedMediaInspector::class);
        $contractOnly = (bool) $this->option('contract-only');
        $rows = [];
        $counts = ['blank' => 0, 'unreadable' => 0, 'ok' => 0, 'skipped' => 0];

        foreach ($drafts as $draft) {
            $contract = is_array($draft->branding_payload['render_contract'] ?? null)
                ? $draft->branding_payload['render_contract']
                : null;
            $isVideo = ComplianceAgent::looksLikeVideo((string) $draft->asset_url);

            $inspection = null;
            if (! ComplianceAgent::contractProvesBlank($contract) && ! $isVideo && ! $contractOnly) {
                $inspection = $inspector->inspectUrl((string) $draft->asset_url);
            }

            $decision = ComplianceAgent::decideMediaQuality($contract, $inspection, $isVideo);

            $bucket = match (true) {
                $decision['result'] === 'pass' => 'ok',
                ($decision['details']['verdict'] ?? null) === RenderedMediaInspector::VERDICT_UNREADABLE => 'unreadable',
                $decision['result'] === 'warning' => 'skipped',
                default => 'blank',
            };
            $counts[$bucket]++;

            if ($bucket === 'ok' || $bucket === 'skipped') {
                continue;
            }

            $rows[] = [
                $draft->id,
                $draft->brand_id,
                $draft->platform,
                $draft->status,
                $decision['details']['layer'] ?? '-',
                number_format((float) ($decision['details']['metrics']['edge_density'] ?? 0), 4),
                mb_substr($decision['reason'], 0, 64),
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['draft', 'brand', 'platform', 'status', 'caught_by', 'edge', 'reason'], $rows);
        }

        $this->newLine();
        $this->line(sprintf(
            'Blank: <fg=red>%d</> | Unreadable: <fg=yellow>%d</> | OK: <fg=green>%d</> | Not inspected: %d',
            $counts['blank'], $counts['unreadable'], $counts['ok'], $counts['skipped'],
        ));

        if ($counts['blank'] > 0 || $counts['unreadable'] > 0) {
            $this->newLine();
            $this->comment('Unpublished drafts: regenerate with `drafts:regenerate-image`.');
            $this->comment('Already-published posts: this command does not touch them — decide per post.');
        }

        // Non-zero exit so CI / a scheduled sweep can alert on it.
        return ($counts['blank'] > 0 || $counts['unreadable'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
