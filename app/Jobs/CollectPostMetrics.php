<?php

namespace App\Jobs;

use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Services\Metrics\BlotatoMetricsCollector;
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
 * Provider routing (v1):
 *   - All platforms: BlotatoMetricsCollector — best-effort pull from
 *     Blotato's post-status endpoint which sometimes echoes platform
 *     metrics. Most platforms require first-party OAuth for real metrics
 *     (logged as v1.1 in followups: per-platform Graph/Data API pulls).
 *   - Manual CSV upload via /agency/performance is the canonical fallback
 *     for any platform Blotato doesn't echo metrics for.
 *
 * Idempotency: writes a NEW snapshot every run (time-series). The
 * dispatcher rate-limits how often a given post is sampled.
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
        $post = ScheduledPost::with('brand')->find($this->scheduledPostId);
        if (! $post) return;
        if ($post->status !== 'published') return;
        if (! $post->blotato_post_id) return;

        try {
            $payload = app(BlotatoMetricsCollector::class)->collect($post);
        } catch (\Throwable $e) {
            Log::warning('CollectPostMetrics: collector failed', [
                'id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (empty($payload)) return;

        PostMetric::create([
            'scheduled_post_id' => $post->id,
            'brand_id' => $post->brand_id,
            'platform' => $post->draft?->platform ?? '?',
            'observed_at' => now(),
            'source' => $payload['source'] ?? 'blotato_status',
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
}
