<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort metrics collection via Blotato's post-status endpoint.
 *
 * Blotato is publish-focused (verified against backend.blotato.com/openapi.json
 * — no dedicated analytics endpoint). The post-status response sometimes
 * carries platform-echoed metrics (likes/comments/shares) once the post is
 * processed, but the shape varies per platform. v1 captures whatever
 * Blotato gives us and stores the raw payload for forensics.
 *
 * v1.1: replace with per-platform first-party OAuth pulls *where the
 * platform actually allows it* — Facebook Graph /insights, IG Graph
 * /insights, YouTube Data /videos, X /tweets/:id with the paid Basic
 * tier. LinkedIn is the exception: r_member_social (read own posts'
 * engagement) is a closed permission as of 2024 — LinkedIn is not
 * accepting new requests. Personal-profile metrics require manual CSV
 * upload until a Company Page migration unlocks the Marketing API.
 * Manual CSV upload at /agency/performance remains the universal
 * "real metrics" fallback.
 */
class BlotatoMetricsCollector
{
    /**
     * Returns a normalised metrics payload keyed for direct PostMetric::create
     * consumption. Empty array if nothing usable came back.
     *
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        if (! $post->blotato_post_id) return [];

        try {
            $client = BlotatoClient::fromConfig();
            $status = $client->getPostStatus($post->blotato_post_id);
        } catch (\Throwable $e) {
            Log::warning('BlotatoMetricsCollector: getPostStatus failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        // Blotato's exact field names vary per platform, so we probe a few
        // common shapes. We don't assume a metrics block exists.
        $metrics = $status['metrics']
            ?? $status['insights']
            ?? $status['analytics']
            ?? null;

        if (! is_array($metrics)) {
            // Some platforms echo counts at the top level on a published row.
            $metrics = array_intersect_key($status, array_flip([
                'likes', 'reactions', 'comments', 'shares', 'reposts',
                'saves', 'views', 'impressions', 'reach', 'clicks',
            ]));
        }

        if (! is_array($metrics) || empty($metrics)) {
            return ['source' => 'blotato_status', 'raw' => $status];
        }

        $likes = $this->firstNumeric($metrics, ['likes', 'reactions', 'reaction_count', 'like_count', 'favorite_count']);
        $comments = $this->firstNumeric($metrics, ['comments', 'comment_count', 'reply_count']);
        $shares = $this->firstNumeric($metrics, ['shares', 'reposts', 'share_count', 'retweet_count']);
        $saves = $this->firstNumeric($metrics, ['saves', 'save_count', 'bookmarks', 'bookmark_count']);
        $impressions = $this->firstNumeric($metrics, ['impressions', 'impression_count']);
        $reach = $this->firstNumeric($metrics, ['reach']);
        $views = $this->firstNumeric($metrics, ['views', 'video_views', 'view_count', 'play_count']);
        $clicks = $this->firstNumeric($metrics, ['clicks', 'url_clicks', 'link_clicks']);
        $profileVisits = $this->firstNumeric($metrics, ['profile_visits', 'profile_views']);

        $engagementRate = null;
        if ($impressions && $impressions > 0) {
            $engagement = (int) ($likes ?? 0) + (int) ($comments ?? 0) + (int) ($shares ?? 0) + (int) ($saves ?? 0);
            $engagementRate = round($engagement / $impressions, 4);
        }

        return [
            'source' => 'blotato_status',
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
            'raw' => $status,
        ];
    }

    /**
     * @param array<string,mixed> $bag
     * @param array<int,string> $keys
     */
    private function firstNumeric(array $bag, array $keys): ?int
    {
        foreach ($keys as $k) {
            if (isset($bag[$k]) && is_numeric($bag[$k])) {
                return (int) $bag[$k];
            }
        }
        return null;
    }
}
