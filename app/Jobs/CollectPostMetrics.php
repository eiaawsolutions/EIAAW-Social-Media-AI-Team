<?php

namespace App\Jobs;

use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Services\Metrics\MetricsProviderRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Collects engagement metrics for one published ScheduledPost. Stores a
 * snapshot row in post_metrics so the Optimizer agent can reason about
 * growth curves, not just last-known values.
 *
 * Provider routing (2026-05-30): delegated to MetricsProviderRouter, which
 * picks per post —
 *   - Meta Graph (first-party): HQ's OWN Instagram/Facebook posts when a
 *     Business Manager System User token is configured. REAL metrics now,
 *     no Meta App Review (Standard Access covers owned accounts).
 *   - Metricool: everything the publisher reports on. Records "no data yet"
 *     until a reading is available, then flows automatically.
 *   - Manual CSV upload via /agency/performance: operator fallback for any
 *     post neither provider can report on.
 * The router is the seam where per-customer Meta OAuth slots in later.
 *
 * Result handling: the collector returns a discriminated result —
 *   'metrics'         → real counters captured, write a full snapshot.
 *   'no_metrics_yet'  → matched the post but no reading yet; write a
 *                       NULL-counter snapshot (so the dashboard distinguishes
 *                       "tried, none available" from "never tried") UNLESS we
 *                       already have an identical no-data row for this fetch
 *                       cycle (dedup on blotato_last_fetched_at).
 *   [] (empty)        → couldn't identify/reach the post; write nothing.
 *
 * Idempotency: time-series by design (a NEW snapshot per real reading). The
 * dispatcher rate-limits sampling; we additionally skip writing duplicate
 * no-data rows so a dormant post doesn't accrue thousands of empty snapshots.
 */
class CollectPostMetrics implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $scheduledPostId) {}

    public function handle(): void
    {
        // Collectors read brand.workspace (provider routing + per-workspace
        // provider key) and draft.platform (which provider / metric set), so
        // eager-load both to avoid lazy-load N+1 inside the queue worker.
        $post = ScheduledPost::with(['brand.workspace', 'draft'])->find($this->scheduledPostId);
        if (! $post) return;
        if ($post->status !== 'published') return;
        if (! $post->blotato_post_id) return;

        try {
            $payload = app(MetricsProviderRouter::class)->collect($post);
        } catch (\Throwable $e) {
            Log::warning('CollectPostMetrics: collector failed', [
                'id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (empty($payload)) return;

        $status = $payload['status'] ?? 'metrics';
        $fetchedAt = $payload['blotato_last_fetched_at'] ?? null;

        // Dedup dormant no-data rows: if the latest snapshot for this post is
        // ALSO a no-data reading from the same fetch cycle (the provider's
        // lastFetchedAt, or null for Meta/404), don't pile on another empty
        // row. (A real reading always writes — it's a new point on the growth
        // curve even if values are unchanged.)
        if ($status === 'no_metrics_yet' && $this->lastSnapshotIsSameNoData($post->id, $fetchedAt)) {
            return;
        }

        PostMetric::create([
            'scheduled_post_id' => $post->id,
            'brand_id' => $post->brand_id,
            'platform' => $post->draft?->platform ?? '?',
            'observed_at' => now(),
            'source' => $payload['source'] ?? 'blotato_analytics',
            'blotato_published_id' => $payload['blotato_published_id'] ?? null,
            'blotato_last_fetched_at' => $fetchedAt,
            'impressions' => $payload['impressions'] ?? null,
            'reach' => $payload['reach'] ?? null,
            'likes' => $payload['likes'] ?? null,
            'comments' => $payload['comments'] ?? null,
            'shares' => $payload['shares'] ?? null,
            'saves' => $payload['saves'] ?? null,
            'video_views' => $payload['video_views'] ?? null,
            'profile_visits' => $payload['profile_visits'] ?? null,
            'url_clicks' => $payload['url_clicks'] ?? null,
            'engagement_rate' => $payload['engagement_rate'] ?? null,
            'raw_payload' => $payload['raw'] ?? null,
        ]);
    }

    /**
     * True when the most-recent snapshot for this post is itself a no-data
     * reading from the same provider fetch cycle ($fetchedAt). Lets us skip
     * writing duplicate empty rows while a post is dormant. A null $fetchedAt
     * (provider gave us no lastFetchedAt) compares as "same cycle" against a
     * prior null so we still de-dupe the pure-404 case.
     */
    private function lastSnapshotIsSameNoData(int $scheduledPostId, ?string $fetchedAt): bool
    {
        $latest = PostMetric::where('scheduled_post_id', $scheduledPostId)
            ->latest('observed_at')
            ->first();

        if (! $latest) return false;          // never sampled → write the first row
        if ($latest->hasReading()) return false; // last row had real data → keep writing

        $prevFetched = optional($latest->blotato_last_fetched_at)->toIso8601String();
        $thisFetched = $fetchedAt ? \Illuminate\Support\Carbon::parse($fetchedAt)->toIso8601String() : null;

        return $prevFetched === $thisFetched;
    }
}
