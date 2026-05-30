<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Meta\MetaGraphClient;
use Illuminate\Support\Facades\Log;

/**
 * First-party metrics via Meta Graph API (Instagram + Facebook).
 *
 * Phase 1 — HQ's OWN accounts only, authed with a Business Manager System
 * User token (Standard Access, no Meta App Review). The collector keys off
 * scheduled_posts.platform_post_id, which for IG/FB posts published through
 * Blotato is the platform media id Meta's /insights endpoint expects.
 *
 * Returns the SAME discriminated shape as BlotatoMetricsCollector so
 * CollectPostMetrics can persist either provider's result uniformly:
 *   ['status'=>'metrics', source, …counters…, 'raw'=>…]
 *   ['status'=>'no_metrics_yet', 'raw'=>…]   (media too new / no insights)
 *   []                                       (not applicable / can't fetch)
 *
 * Truthfulness Contract: NULL where Meta omits a counter, never a fabricated
 * zero. A permission error is logged loudly (operator must fix the token);
 * a no-data result is quiet (brand-new media legitimately has none yet).
 */
class MetaMetricsCollector
{
    /** Platforms this collector serves. */
    public const PLATFORMS = ['instagram', 'facebook'];

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        $platform = (string) ($post->draft?->platform ?? '');
        if (! in_array($platform, self::PLATFORMS, true)) {
            return [];
        }

        // The Graph /insights endpoint needs the platform media id, which we
        // capture as platform_post_id on publish. Without it there's nothing
        // to query (the post may not be verified-published yet).
        $mediaId = (string) ($post->platform_post_id ?? '');
        if ($mediaId === '') {
            return [];
        }

        $result = $this->client->getMediaInsights($mediaId);

        if (! ($result['found'] ?? false)) {
            $reason = $result['reason'] ?? 'no_data';
            if ($reason === 'permission') {
                // Operator-actionable: token lacks scope or lost access to the
                // asset. Loud so it surfaces, but we still return no-data so
                // the pipeline doesn't crash on one bad connection.
                Log::warning('MetaMetricsCollector: permission/token problem', [
                    'post_id' => $post->id,
                    'media_id' => $mediaId,
                    'raw' => $result['raw'] ?? null,
                ]);
            }
            // 'no_data' (brand-new media) and 'http_error' (transient) → quiet
            // no-metrics-yet so the job can record "tried, none available".
            return [
                'status' => 'no_metrics_yet',
                'source' => 'meta_graph',
                'raw' => $result['raw'] ?? null,
            ];
        }

        return $this->normalise($result['metrics'], $result['raw'] ?? null);
    }

    /**
     * Map Meta's IG/FB insight names onto our typed PostMetric columns.
     * Meta names per https://developers.facebook.com/docs/instagram-platform/insights:
     *   reach, impressions, likes, comments, shares, saved, views (reels/video).
     * (Older 'engagement' = likes+comments; we prefer the discrete counters.)
     *
     * @param  array<string,int>  $m
     * @return array<string,mixed>
     */
    private function normalise(array $m, mixed $raw): array
    {
        $impressions = $m['impressions'] ?? null;
        $reach = $m['reach'] ?? null;
        $likes = $m['likes'] ?? null;
        $comments = $m['comments'] ?? null;
        $shares = $m['shares'] ?? null;
        $saves = $m['saved'] ?? ($m['saves'] ?? null);
        $views = $m['views'] ?? ($m['video_views'] ?? ($m['plays'] ?? null));
        $profileVisits = $m['profile_visits'] ?? ($m['profile_activity'] ?? null);
        $clicks = $m['website_clicks'] ?? ($m['link_clicks'] ?? null);

        $engagementRate = null;
        if ($impressions && $impressions > 0) {
            $engagement = (int) ($likes ?? 0) + (int) ($comments ?? 0) + (int) ($shares ?? 0) + (int) ($saves ?? 0);
            $engagementRate = round($engagement / $impressions, 4);
        }

        return [
            'status' => 'metrics',
            'source' => 'meta_graph',
            'impressions' => $impressions,
            'reach' => $reach,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $saves,
            'video_views' => $views,
            'profile_visits' => $profileVisits,
            'url_clicks' => $clicks,
            'engagement_rate' => $engagementRate,
            'raw' => $raw,
        ];
    }
}
