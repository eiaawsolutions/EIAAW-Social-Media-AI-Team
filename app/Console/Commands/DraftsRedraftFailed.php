<?php

namespace App\Console\Commands;

use App\Jobs\RedraftFailedDraft;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cron entry point for the auto-redraft loop.
 *
 * Picks every Draft that:
 *   - status = 'compliance_failed'
 *   - revision_count < MAX_REVISIONS
 *   - last_redraft_at older than --cooldown minutes (or never tried)
 *
 * Dispatches a RedraftFailedDraft job per pick. Bounded by --limit so a
 * backlog of fails can't fan out a thousand simultaneous LLM calls. Default
 * cron cadence is every 5 minutes (see bootstrap/app.php).
 */
class DraftsRedraftFailed extends Command
{
    protected $signature = 'drafts:redraft-failed
                            {--limit=20 : max drafts to redraft per run}
                            {--cooldown=10 : minimum minutes between redraft attempts on the same draft}
                            {--dry-run : list what would be redrafted, do not dispatch}';

    protected $description = 'Auto-redraft compliance-failed drafts via Writer + re-run Compliance. Closes the held-draft loop.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cooldownMin = max(1, (int) $this->option('cooldown'));
        $dry = (bool) $this->option('dry-run');

        $cooldownCutoff = Carbon::now()->subMinutes($cooldownMin);

        $drafts = Draft::query()
            ->where('status', 'compliance_failed')
            ->where('revision_count', '<', RedraftFailedDraft::MAX_REVISIONS)
            ->whereNotNull('calendar_entry_id')
            ->where(function ($q) use ($cooldownCutoff) {
                $q->whereNull('last_redraft_at')
                  ->orWhere('last_redraft_at', '<', $cooldownCutoff);
            })
            // Oldest first — fail-FIFO so a single recent backlog doesn't
            // starve drafts that have been waiting.
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'platform', 'brand_id', 'revision_count']);

        if ($drafts->isEmpty()) {
            $this->info('No compliance-failed drafts under the retry cap.');
            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($drafts as $draft) {
            $line = sprintf(
                'draft #%d (%s) brand=%d revision=%d/%d',
                $draft->id, $draft->platform, $draft->brand_id,
                $draft->revision_count ?? 0, RedraftFailedDraft::MAX_REVISIONS,
            );

            if ($dry) {
                $this->line('[dry] would redraft '.$line);
                continue;
            }

            RedraftFailedDraft::dispatch($draft->id);
            $dispatched++;
            $this->info('Dispatched redraft for '.$line);
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line("dispatched: {$dispatched}");
        $this->line('cap:        '.RedraftFailedDraft::MAX_REVISIONS.' attempts/draft');
        $this->line("cooldown:   {$cooldownMin} min between attempts");

        return self::SUCCESS;
    }
}
