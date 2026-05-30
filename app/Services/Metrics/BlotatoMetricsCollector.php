<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Support\Facades\Log;

/**
 * Metrics collection via Blotato's analytics API.
 *
 * History: the original collector (pre-2026-05-30) read engagement off the
 * post-STATUS endpoint, because Blotato had no analytics surface at the time.
 * Blotato has since shipped two analytics ROUTES —
 *   GET /v2/published-posts             (bridge: postUrl -> publishedPostId)
 *   GET /v2/posts/{publishedPostId}/analytics  (per-post metrics)
 * — so this collector now targets them directly.
 *
 * DORMANT BY DESIGN: as verified live on 2026-05-30, Blotato's engagement-
 * tracking BACKEND is still on its roadmap. The routes authenticate but every
 * published post returns HTTP 404 {"message":"Analytics not available"} with
 * empty metricsHistory, regardless of post age or (active, paid) plan tier.
 * Until Blotato turns analytics on for the account this collector returns a
 * no-data result every run — we record that we tried (no fabricated zeros,
 * Truthfulness Contract) and capture nothing. The MOMENT Blotato starts
 * returning 200 with metrics, real numbers flow automatically with no code
 * change: the 30-min metrics:collect cron already drives this path, and the
 * postUrl->publishedPostId join was confirmed against live data.
 *
 * Join reality (verified live): published-posts items are keyed by the
 * platform `id` (publishedPostId), NOT the submission id we store as
 * scheduled_posts.blotato_post_id. There is no submission-id field on the
 * item, so we bridge on postUrl == scheduled_posts.platform_post_url. A post
 * with no captured platform_post_url therefore cannot be matched — that is the
 * same verification gap MetricsCsvImporter documents, and the operator's CSV
 * upload at /agency/performance remains the fallback for it.
 */
class BlotatoMetricsCollector
{
    /**
     * Collect metrics for one published post. Return shape is a discriminated
     * result the job persists directly:
     *
     *   ['status' => 'metrics', …normalised counters…, 'raw' => …]
     *       Blotato returned real metrics. Counters are ints (Blotato sends
     *       them as strings; we cast). NULL where the platform omits a counter.
     *
     *   ['status' => 'no_metrics_yet', 'blotato_published_id' => ?string, 'raw' => …]
     *       The post is matched but Blotato has no analytics for it yet
     *       (the 404 / roadmap case). We still record a snapshot so the
     *       dashboard can show "tried, none available" honestly.
     *
     *   []  — nothing usable (no workspace, no platform_post_url to match,
     *         Blotato unreachable). The job writes no snapshot.
     *
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        if (! $post->blotato_post_id) {
            return [];
        }

        // We bridge on the platform post URL, so without one there is nothing
        // to match against /v2/published-posts.
        $postUrl = (string) ($post->platform_post_url ?? '');
        if ($postUrl === '') {
            return [];
        }

        // Per-workspace client — Blotato's analytics only cover posts on the
        // same account that published them, so a global fallback would return
        // empty for every customer post. Resolve from the post's workspace.
        $workspace = $post->brand?->workspace;
        if (! $workspace) {
            Log::warning('BlotatoMetricsCollector: post has no workspace context', [
                'post_id' => $post->id,
            ]);
            return [];
        }

        try {
            $client = BlotatoClient::forWorkspace($workspace);
        } catch (\Throwable $e) {
            Log::warning('BlotatoMetricsCollector: client construction failed', [
                'post_id' => $post->id,
                'workspace_id' => $workspace->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        // Step 1: resolve our post to Blotato's publishedPostId via postUrl.
        $publishedId = $this->resolvePublishedId($client, $post, $postUrl);
        if ($publishedId === null) {
            // Either the list call failed or our URL isn't in Blotato's
            // published set. Either way: nothing to fetch. Don't write a
            // snapshot — we couldn't even identify the post.
            return [];
        }

        // Step 2: fetch analytics for that publishedPostId.
        try {
            $result = $client->getPostAnalytics($publishedId);
        } catch (\Throwable $e) {
            Log::warning('BlotatoMetricsCollector: getPostAnalytics failed', [
                'post_id' => $post->id,
                'published_id' => $publishedId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        // The expected case TODAY: Blotato has no metrics for the post yet.
        if (! ($result['found'] ?? false)) {
            return [
                'status' => 'no_metrics_yet',
                'blotato_published_id' => $publishedId,
                'raw' => $result['body'] ?? null,
            ];
        }

        $body = (array) ($result['body'] ?? []);
        $metrics = is_array($body['metrics'] ?? null) ? $body['metrics'] : [];

        // If Blotato returned 200 but an empty metrics block, still a no-data
        // reading rather than a fabricated zero set.
        if ($metrics === []) {
            return [
                'status' => 'no_metrics_yet',
                'blotato_published_id' => $publishedId,
                'blotato_last_fetched_at' => $body['lastFetchedAt'] ?? null,
                'raw' => $body,
            ];
        }

        return $this->normalise($metrics, $publishedId, $body);
    }

    /**
     * Find the platform publishedPostId for our post by matching postUrl
     * against the /v2/published-posts list. Memoised within the request via
     * the Blotato client's own per-request behaviour is not guaranteed, so we
     * keep this call cheap (limit + platform filter) and tolerant.
     */
    private function resolvePublishedId(BlotatoClient $client, ScheduledPost $post, string $postUrl): ?string
    {
        $platform = (string) ($post->draft?->platform ?? '');
        $query = ['limit' => 100];
        if ($platform !== '') {
            // Blotato labels X as 'twitter'; map our enum back for the filter.
            $query['platform'] = $platform === 'x' ? 'twitter' : $platform;
        }

        try {
            $items = $client->getPublishedPosts($query);
        } catch (\Throwable $e) {
            Log::warning('BlotatoMetricsCollector: getPublishedPosts failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $needle = $this->normaliseUrl($postUrl);
        foreach ($items as $item) {
            $candidate = $this->normaliseUrl((string) ($item['postUrl'] ?? ''));
            if ($candidate !== '' && $candidate === $needle) {
                $id = $item['id'] ?? $item['publishedPostId'] ?? null;
                return is_scalar($id) ? (string) $id : null;
            }
        }

        return null;
    }

    /**
     * Map Blotato's analytics metrics block (string values) onto our typed
     * PostMetric columns. Blotato field names verified against the analytics
     * docs; we probe a few aliases per counter for resilience.
     *
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function normalise(array $metrics, string $publishedId, array $body): array
    {
        $likes = $this->firstNumeric($metrics, ['likesCount', 'likes', 'reactionsCount', 'reactions']);
        $comments = $this->firstNumeric($metrics, ['commentsCount', 'comments', 'repliesCount', 'replies']);
        $shares = $this->firstNumeric($metrics, ['sharesCount', 'shares', 'repostsCount', 'reposts', 'twitterRetweetsCount']);
        $saves = $this->firstNumeric($metrics, ['savesCount', 'saves', 'bookmarksCount', 'bookmarks']);
        $impressions = $this->firstNumeric($metrics, ['impressionsCount', 'impressions']);
        $reach = $this->firstNumeric($metrics, ['reachCount', 'reach']);
        $views = $this->firstNumeric($metrics, ['viewsCount', 'views', 'playsCount', 'plays', 'videoViewsCount']);
        $clicks = $this->firstNumeric($metrics, ['clicksCount', 'clicks', 'navigationsCount', 'urlClicksCount']);
        $profileVisits = $this->firstNumeric($metrics, ['profileVisitsCount', 'profileVisits', 'profileActivityCount']);

        $engagementRate = null;
        if ($impressions && $impressions > 0) {
            $engagement = (int) ($likes ?? 0) + (int) ($comments ?? 0) + (int) ($shares ?? 0) + (int) ($saves ?? 0);
            $engagementRate = round($engagement / $impressions, 4);
        }

        return [
            'status' => 'metrics',
            'source' => 'blotato_analytics',
            'blotato_published_id' => $publishedId,
            'blotato_last_fetched_at' => $body['lastFetchedAt'] ?? null,
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
            'raw' => $body,
        ];
    }

    /**
     * Lowercase + strip trailing slash so trivially-different URL spellings
     * (http vs https handled by client, trailing slash, case) still match.
     */
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
