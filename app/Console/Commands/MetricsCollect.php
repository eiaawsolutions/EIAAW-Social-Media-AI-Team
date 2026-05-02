<?php

namespace App\Console\Commands;

use App\Jobs\CollectPostMetrics;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tiered metrics collection. Tighter sampling early (when posts grow
 * fastest) and looser sampling later (where deltas are smaller).
 *
 * Schedule policy:
 *   - Published in last 24h:  re-collect every 30 min  (high-velocity window)
 *   - Published 1-7d ago:     re-collect every 6h
 *   - Published 7-30d ago:    re-collect every 24h
 *   - Older than 30d:         skip (cold storage; CSV upload only)
 *
 * The cron entry runs every 30 min. Each tier is gated by checking
 * "last metric snapshot for this post is older than the tier interval".
 */
class MetricsCollect extends Command
{
    protected $signature = 'metrics:collect {--limit=200}';
    protected $description = 'Dispatch CollectPostMetrics jobs for published posts on a tiered schedule.';

    public function handle(): int
    {
        $now = Carbon::now();
        $limit = (int) $this->option('limit');
        $dispatched = 0;

        // Tier 1: published in last 24h, last snapshot > 30 min ago.
        $hot = ScheduledPost::where('status', 'published')
            ->where('published_at', '>=', $now->copy()->subDay())
            ->whereNotNull('blotato_post_id')
            ->limit($limit)
            ->get()
            ->filter(fn (ScheduledPost $p) => $this->lastMetricAt($p)?->diffInMinutes($now) > 30 || $this->lastMetricAt($p) === null);
        foreach ($hot as $p) {
            CollectPostMetrics::dispatch($p->id)->onQueue('metrics');
            $dispatched++;
        }

        // Tier 2: published 1-7d ago, last snapshot > 6h ago.
        $warm = ScheduledPost::where('status', 'published')
            ->whereBetween('published_at', [$now->copy()->subDays(7), $now->copy()->subDay()])
            ->whereNotNull('blotato_post_id')
            ->limit($limit)
            ->get()
            ->filter(fn (ScheduledPost $p) => $this->lastMetricAt($p)?->diffInHours($now) > 6 || $this->lastMetricAt($p) === null);
        foreach ($warm as $p) {
            CollectPostMetrics::dispatch($p->id)->onQueue('metrics');
            $dispatched++;
        }

        // Tier 3: published 7-30d ago, last snapshot > 24h ago.
        $cold = ScheduledPost::where('status', 'published')
            ->whereBetween('published_at', [$now->copy()->subDays(30), $now->copy()->subDays(7)])
            ->whereNotNull('blotato_post_id')
            ->limit($limit)
            ->get()
            ->filter(fn (ScheduledPost $p) => $this->lastMetricAt($p)?->diffInDays($now) > 1 || $this->lastMetricAt($p) === null);
        foreach ($cold as $p) {
            CollectPostMetrics::dispatch($p->id)->onQueue('metrics');
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} metrics-collection job(s).");
        return self::SUCCESS;
    }

    private function lastMetricAt(ScheduledPost $post): ?Carbon
    {
        $latest = PostMetric::where('scheduled_post_id', $post->id)
            ->latest('observed_at')
            ->value('observed_at');
        return $latest ? Carbon::parse($latest) : null;
    }
}
