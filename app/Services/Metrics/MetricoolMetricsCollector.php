<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Metrics collection via Metricool's per-post analytics API.
 *
 * This is the WORKING metrics path the Blotato→Metricool switch was about:
 * Metricool reads real engagement per post, where BlotatoMetricsCollector is
 * dormant (404s for every post). Field mappings here were verified LIVE on
 * 2026-05-30 against blogId 6322515 (37 IG posts, 8 TikTok posts) — see memory
 * metricool-field-map for the full per-network shape.
 *
 * Multi-tenancy: Metricool is natively multi-brand — ONE shared token covers
 * all brands; each brand is addressed by its numeric blogId
 * (brands.metricool_blog_id). So, unlike BlotatoMetricsCollector::forWorkspace,
 * this collector uses a single MetricoolClient::fromConfig() and scopes every
 * call by the post's brand blogId. A post whose brand has no blogId yields
 * [] (router falls back to the other providers).
 *
 * Join strategy: Metricool's analytics endpoint returns a LIST of the brand's
 * posts for a network+window. We bridge our post to the right row by matching
 * scheduled_posts.platform_post_url against the post's url/shareUrl field
 * (normalised: lowercase, no trailing slash) — the same postUrl-bridge pattern
 * BlotatoMetricsCollector uses, because Metricool also has no field carrying
 * our internal id. A post with no captured platform_post_url cannot be matched
 * (the documented verification gap; CSV upload remains the fallback).
 *
 * Returns the SAME discriminated result shape as Meta/Blotato collectors so
 * CollectPostMetrics persists it uniformly:
 *   ['status'=>'metrics', 'source'=>'metricool', …counters…, 'raw'=>…]
 *   ['status'=>'no_metrics_yet', 'source'=>'metricool', 'raw'=>…]
 *   []  (not applicable / can't identify / unconfigured)
 *
 * Truthfulness Contract: NULL where Metricool (or the platform) omits a
 * counter — never a fabricated zero. E.g. TikTok has no `reach` in its API, so
 * reach is NULL for TikTok posts; that is correct, not a bug.
 */
class MetricoolMetricsCollector
{
    /** Networks we currently map. Others fall back to other providers. */
    public const NETWORKS = ['instagram', 'tiktok', 'linkedin', 'facebook', 'x', 'threads', 'pinterest', 'youtube'];

    /** How far back to ask Metricool for the brand's posts when matching. */
    private const LOOKBACK_DAYS = 180;

    public function __construct(private readonly ?MetricoolClient $client) {}

    /**
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        // Unconfigured (no token/userId) → nothing to do; router falls back.
        if ($this->client === null) {
            return [];
        }

        $platform = (string) ($post->draft?->platform ?? '');
        $network = $this->networkFor($platform);
        if ($network === null) {
            return [];
        }

        // Multi-tenant key: the brand's Metricool blogId. Unmapped → fall back.
        $blogId = $post->brand?->metricool_blog_id;
        if (! $blogId) {
            return [];
        }

        // We bridge on the platform post URL; without one there is no row to
        // match in Metricool's list.
        $postUrl = (string) ($post->platform_post_url ?? '');
        if ($postUrl === '') {
            return [];
        }

        try {
            $result = $this->client->postAnalytics(
                blogId: (int) $blogId,
                from: Carbon::now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->format('Y-m-d\TH:i:s'),
                to: Carbon::now()->endOfDay()->format('Y-m-d\TH:i:s'),
                network: $network,
            );
        } catch (\Throwable $e) {
            Log::warning('MetricoolMetricsCollector: postAnalytics failed', [
                'post_id' => $post->id,
                'blog_id' => $blogId,
                'network' => $network,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (! ($result['found'] ?? false)) {
            // Endpoint 404 (network not on plan / unavailable). Record a
            // no-data snapshot rather than fabricating zeros.
            return ['status' => 'no_metrics_yet', 'source' => 'metricool', 'raw' => $result['body'] ?? null];
        }

        $posts = $this->extractPosts($result['body']);
        $match = $this->matchByUrl($posts, $postUrl);

        if ($match === null) {
            // Reached the endpoint but our post isn't in the returned set yet
            // (too new, outside window, or platform_post_url mismatch). Honest
            // "tried, none available" — not a zero.
            return ['status' => 'no_metrics_yet', 'source' => 'metricool', 'raw' => null];
        }

        return $this->normalise($network, $match);
    }

    /** Map our internal platform enum to Metricool's network slug. */
    private function networkFor(string $platform): ?string
    {
        $platform = strtolower($platform);
        if (! in_array($platform, self::NETWORKS, true)) {
            return null;
        }
        // Metricool uses 'twitter' for X (same legacy naming as Blotato).
        return $platform === 'x' ? 'twitter' : $platform;
    }

    /**
     * Normalise one Metricool post object onto our typed PostMetric columns.
     * Mappings verified live 2026-05-30 (memory metricool-field-map):
     *
     *   Instagram: impressions←impressionsTotal, reach←reach, likes←likes,
     *     comments←comments, shares←shares, saves←saved, video_views←views,
     *     engagement_rate = interactions/impressionsTotal (interactions is a
     *     COUNT, so we derive the rate). profile_visits/url_clicks → NULL.
     *
     *   TikTok: impressions←viewCount, likes←likeCount, comments←commentCount,
     *     shares←shareCount. reach → NULL (TikTok's API doesn't expose it —
     *     platform limit, NOT a defect). engagement_rate ← engagement when
     *     numeric, else derived from the counters / viewCount.
     *
     *   Other networks: probe a superset of field aliases; absent → NULL.
     *
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    public function normalise(string $network, array $p): array
    {
        $impressions = $this->firstNumeric($p, ['impressionsTotal', 'impressions', 'viewCount', 'views', 'impressionCount']);
        $reach = $this->firstNumeric($p, ['reach', 'reachCount', 'uniqueImpressions']);
        $likes = $this->firstNumeric($p, ['likes', 'likeCount', 'reactions', 'diggCount']);
        $comments = $this->firstNumeric($p, ['comments', 'commentCount', 'replies']);
        $shares = $this->firstNumeric($p, ['shares', 'shareCount', 'reposts', 'retweets']);
        $saves = $this->firstNumeric($p, ['saved', 'saves', 'saveCount', 'bookmarks']);
        $videoViews = $this->firstNumeric($p, ['videoViews', 'views', 'viewCount', 'plays', 'playCount']);
        $profileVisits = $this->firstNumeric($p, ['profileVisits', 'profileViews', 'profileActivity']);
        $urlClicks = $this->firstNumeric($p, ['urlClicks', 'linkClicks', 'websiteClicks', 'clicks']);

        // engagement_rate: prefer a derived rate from impressions (consistent
        // with MetaMetricsCollector). Metricool's `interactions`/`engagement`
        // are COUNTS, not rates — used only as a fallback engagement numerator.
        $interactions = $this->firstNumeric($p, ['interactions', 'engagement']);
        $engagementRate = null;
        if ($impressions && $impressions > 0) {
            $engagementSum = $interactions
                ?? ((int) ($likes ?? 0) + (int) ($comments ?? 0) + (int) ($shares ?? 0) + (int) ($saves ?? 0));
            $engagementRate = round($engagementSum / $impressions, 4);
        }

        return [
            'status' => 'metrics',
            'source' => 'metricool',
            'impressions' => $impressions,
            'reach' => $reach,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $saves,
            'video_views' => $videoViews,
            'profile_visits' => $profileVisits,
            'url_clicks' => $urlClicks,
            'engagement_rate' => $engagementRate,
            'raw' => $p,
        ];
    }

    /**
     * Metricool analytics bodies vary by shape; pull the list of post objects.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractPosts(mixed $body): array
    {
        if (is_array($body) && array_is_list($body)) {
            return $body;
        }
        if (is_array($body)) {
            foreach (['data', 'posts', 'items', 'timeline', 'results'] as $key) {
                if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                    return $body[$key];
                }
            }
        }
        return [];
    }

    /**
     * Find the post whose url/shareUrl matches our platform_post_url.
     *
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,mixed>|null
     */
    private function matchByUrl(array $posts, string $postUrl): ?array
    {
        $needle = $this->normaliseUrl($postUrl);
        if ($needle === '') {
            return null;
        }
        foreach ($posts as $p) {
            if (! is_array($p)) {
                continue;
            }
            foreach (['url', 'shareUrl', 'postUrl', 'embedLink', 'permalink'] as $field) {
                $candidate = $this->normaliseUrl((string) ($p[$field] ?? ''));
                if ($candidate !== '' && $candidate === $needle) {
                    return $p;
                }
            }
        }
        return null;
    }

    private function normaliseUrl(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /**
     * @param  array<string,mixed>  $bag
     * @param  array<int,string>  $keys
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
