<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * DECOMMISSIONED.
 *
 * This command re-verified every ScheduledPost marked `published` against
 * Blotato's actual status (Blotato was the publishing provider), then either
 * confirmed the row (saving the real platform_post_id / platform_post_url) or
 * downgraded it to `submitted` so the live feed stopped claiming a post
 * existed when the platform had no record of it.
 *
 * Blotato has been decommissioned. Publishing now runs through Metricool, and
 * media is normalized at publish time by MetricoolPublisher::submit(). There is
 * no Blotato `getPostStatus` source of truth left to reconcile against, so this
 * command no longer applies. It is kept as a registered no-op to avoid breaking
 * any scheduler/cron entry or operator muscle-memory that still references it;
 * it logs the decommission notice and exits successfully without touching the DB.
 */
class PostsReconcilePublished extends Command
{
    protected $signature = 'posts:reconcile-published
                            {--limit=200 : (ignored) retained for back-compat}
                            {--platform= : (ignored) retained for back-compat}
                            {--apply : (ignored) retained for back-compat}';

    protected $description = 'DECOMMISSIONED — Blotato publish-status reconciliation no longer applies (publishing is via Metricool).';

    public function handle(): int
    {
        $this->warn('Blotato decommissioned — reconciliation no longer applicable. Publishing runs through Metricool, which normalizes media at publish time; there is no Blotato status to reconcile against. No rows were touched.');

        return self::SUCCESS;
    }
}
