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
        $match = $this->matchPost($platform, $posts, $postUrl, $post->platform_post_id, (string) ($post->draft?->body ?? ''));

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
     * Find OUR post inside Metricool's returned list. PUBLIC + per-platform so
     * it is unit-testable against the real shapes captured live 2026-06-02.
     *
     * Why this replaced the old exact-string matchByUrl: Metricool reports a
     * post's URL in a DIFFERENT shape than the one we stored at publish, so a
     * literal compare matched only Instagram/Threads and silently dropped
     * LinkedIn/TikTok/YouTube (the "metrics pending" gap). The differences are:
     *   - a `www.` prefix and/or protocol/case difference
     *   - a `?utm_…` query suffix Metricool appends (TikTok)
     *   - a different URL FIELD name (YouTube uses `watchUrl`, FB uses `link`)
     *   - a different URN representation (LinkedIn share↔ugcPost)
     *
     * Strategy: reduce BOTH sides to a per-platform CANONICAL post id (the
     * stable bit — IG/Threads shortcode, TikTok/YouTube video id, LinkedIn
     * activity number, etc.) and compare those. We also read Metricool's own id
     * fields (videoId/postId/shortCode/id), which carry the same canonical id
     * even when the URL field is absent. Falls back to a normalised-URL compare
     * for any platform we don't special-case. Returns null on no match — never
     * a cross-post false positive (see the LinkedIn share≠ugcPost test).
     *
     * @param  array<int,array<string,mixed>>  $posts  Metricool's post list
     * @param  string|null  $ourUrl  scheduled_posts.platform_post_url
     * @param  string|null  $ourId   scheduled_posts.platform_post_id (usually null today)
     * @param  string|null  $ourCaption  draft.body — the LAST-RESORT bridge for
     *         posts whose id/URL can't match (LinkedIn share≠ugcPost). Matched
     *         on a leading slice against the row's caption field.
     * @return array<string,mixed>|null
     */
    public function matchPost(string $platform, array $posts, ?string $ourUrl, ?string $ourId, ?string $ourCaption = null): ?array
    {
        // Our canonical key: prefer the captured platform id, else derive from
        // the stored permalink.
        $needleKey = $this->canonicalPostKey($platform, (string) ($ourId ?? ''))
            ?? $this->canonicalPostKey($platform, (string) ($ourUrl ?? ''));
        $needleUrl = $this->normaliseUrl((string) ($ourUrl ?? ''));

        foreach ($posts as $p) {
            if (! is_array($p)) {
                continue;
            }

            // 1) Canonical-id match — robust across www/query/URN/field-name.
            if ($needleKey !== null) {
                foreach ($this->candidateIdentifiers($p) as $candidate) {
                    if ($this->canonicalPostKey($platform, $candidate) === $needleKey) {
                        return $p;
                    }
                }
            }

            // 2) Fallback: exact normalised-URL compare (covers any platform
            //    not special-cased by canonicalPostKey).
            if ($needleUrl !== '') {
                foreach (['url', 'shareUrl', 'postUrl', 'embedLink', 'permalink', 'watchUrl', 'link'] as $field) {
                    if ($this->normaliseUrl((string) ($p[$field] ?? '')) === $needleUrl) {
                        return $p;
                    }
                }
            }
        }

        // 3) LAST-RESORT: caption-text bridge. Only reached when NO post id/url
        //    matched — the LinkedIn share≠ugcPost case, where the post genuinely
        //    cannot be id-joined. Match our draft body against the row's caption
        //    field on a stable leading slice (Metricool may append hashtags /
        //    truncate). Gated on a distinctive minimum length AND UNIQUENESS:
        //    if two rows share our caption prefix we ABSTAIN (return null) rather
        //    than risk attributing the wrong post's metrics — the Truthfulness
        //    Contract favours "no data" over "wrong data".
        $ourCap = $this->normaliseCaption((string) ($ourCaption ?? ''));
        if ($ourCap !== null) {
            $hits = [];
            foreach ($posts as $p) {
                if (! is_array($p)) {
                    continue;
                }
                foreach (['comment', 'content', 'text', 'videoDescription', 'description', 'title'] as $field) {
                    $rowCap = $this->normaliseCaption((string) ($p[$field] ?? ''), enforceMin: false);
                    if ($rowCap !== null && $this->captionsMatch($ourCap, $rowCap)) {
                        $hits[] = $p;
                        break; // one field hit per row is enough
                    }
                }
            }
            // Exactly one row matched our caption → confident bridge. Zero or
            // ambiguous (>1) → abstain.
            if (count($hits) === 1) {
                return $hits[0];
            }
        }

        return null;
    }

    /**
     * Two normalised captions identify the same post iff one is a prefix of the
     * other over a meaningful comparable length — Metricool may append hashtags
     * or truncate the tail, and our body may carry a longer tail than the
     * platform shows. We compare the COMMON leading length (min of the two,
     * capped) so a mid-string divergence (e.g. our "…step and nobody" vs the
     * platform's "…step #ai") does NOT match, but a clean prefix does.
     */
    private function captionsMatch(string $a, string $b): bool
    {
        $n = min(mb_strlen($a), mb_strlen($b), 80);
        // Need a meaningfully long shared opener to be confident.
        if ($n < 30) {
            return false;
        }
        return mb_substr($a, 0, $n) === mb_substr($b, 0, $n);
    }

    /**
     * Normalise a caption for comparison: lowercase, collapse whitespace, strip
     * emoji/punctuation that the platform may render differently than we stored.
     * Returns null when OUR caption is too short to be a SAFE distinctive bridge
     * ($enforceMin); a row's caption can be any length (we compare on the common
     * leading length, so a long body or a short platform render both work).
     */
    private function normaliseCaption(string $caption, bool $enforceMin = true): ?string
    {
        $s = strtolower(trim($caption));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;          // whitespace → single space
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', '', $s) ?? $s; // drop emoji/quotes/punct
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        // Distinctiveness floor for OUR caption: a too-short body can't safely
        // identify a single post.
        if ($enforceMin && mb_strlen($s) < 30) {
            return null;
        }
        return $s;
    }

    /**
     * Every string in a Metricool post object that could carry the post's
     * identity (URL fields + id fields). canonicalPostKey() distils each to the
     * stable key, so we feed it the superset.
     *
     * @param  array<string,mixed>  $p
     * @return array<int,string>
     */
    private function candidateIdentifiers(array $p): array
    {
        $out = [];
        foreach (['url', 'shareUrl', 'postUrl', 'embedLink', 'permalink', 'watchUrl', 'link',
                  'videoId', 'postId', 'shortCode', 'id'] as $field) {
            $v = $p[$field] ?? null;
            if (is_scalar($v) && (string) $v !== '') {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * Reduce a URL OR a bare id to the platform's stable canonical post key.
     * Returns null when no key can be extracted (caller then can't id-match;
     * the URL fallback may still apply).
     *
     * Per-platform key (verified against live prod shapes 2026-06-02):
     *   instagram  shortcode from /p|reel|tv/<code>   ('ig:<code>')
     *   threads    shortcode from /post/<code>, or a bare shortCode ('th:<code>')
     *   tiktok     numeric video id from /video/<n> or a bare videoId ('tt:<n>')
     *   youtube    11-char video id from watch?v=/shorts//youtu.be or videoId ('yt:<id>')
     *   linkedin   numeric activity id from urn:li:(share|ugcPost|activity):<n>
     *              — NOTE share and ugcPost carry DIFFERENT numbers for the same
     *              post, so a share-id will not match an ugcPost-id (documented
     *              limitation, not a bug). ('li:<n>')
     *   facebook   posts/<id> or videos/<id> or a bare postId ('fb:<id>')
     *   x/twitter  status/<id>                                   ('tw:<id>')
     *   pinterest  pin/<n>                                       ('pin:<n>')
     */
    private function canonicalPostKey(string $platform, string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $low = strtolower($s);

        return match (strtolower($platform)) {
            'instagram' => preg_match('#(?:/(?:p|reel|tv)/)([a-z0-9_-]+)#i', $s, $m)
                ? 'ig:' . strtolower($m[1])
                : (preg_match('#^[a-z0-9_-]{5,}$#i', $s) ? 'ig:' . $low : null),
            'threads' => preg_match('#/post/([a-z0-9_-]+)#i', $s, $m)
                ? 'th:' . strtolower($m[1])
                : (preg_match('#^[a-z0-9_-]{5,}$#i', $s) ? 'th:' . $low : null),
            'tiktok' => preg_match('#/(?:video|photo)/(\d+)#', $s, $m)
                ? 'tt:' . $m[1]
                : (preg_match('#^\d{6,}$#', $s) ? 'tt:' . $s : null),
            'youtube' => preg_match('#(?:watch\?v=|/shorts/|/live/|youtu\.be/)([a-z0-9_-]{6,})#i', $s, $m)
                ? 'yt:' . $m[1]
                : (preg_match('#^[a-z0-9_-]{6,15}$#i', $s) ? 'yt:' . $s : null),
            // LinkedIn: pull the trailing numeric id from a urn (share/ugcPost/
            // activity) OR from the URL. The URN TYPE is intentionally dropped —
            // we key on the number — but share vs ugcPost numbers differ, so
            // those simply won't collide. A bare numeric id also works.
            'linkedin' => preg_match('#urn:li:(?:share|ugcpost|activity):(\d+)#i', $low, $m)
                ? 'li:' . $m[1]
                : (preg_match('#(\d{8,})#', $s, $m2) ? 'li:' . $m2[1] : null),
            'facebook' => preg_match('#/(?:posts|videos|reel)/(\d+)#', $s, $m)
                ? 'fb:' . $m[1]
                : (preg_match('#[?&]v=(\d+)#', $s, $m2) ? 'fb:' . $m2[1]
                    : (preg_match('#^\d{6,}$#', $s) ? 'fb:' . $s : null)),
            'x', 'twitter' => preg_match('#/status/(\d+)#', $s, $m)
                ? 'tw:' . $m[1]
                : (preg_match('#^\d{6,}$#', $s) ? 'tw:' . $s : null),
            'pinterest' => preg_match('#/pin/(\d+)#', $s, $m)
                ? 'pin:' . $m[1]
                : (preg_match('#^\d{6,}$#', $s) ? 'pin:' . $s : null),
            default => null,
        };
    }

    private function normaliseUrl(string $url): string
    {
        // Lowercase, strip a query/fragment (Metricool appends ?utm_…), drop a
        // leading www. and the scheme, and trim a trailing slash so two
        // otherwise-identical permalinks compare equal.
        $u = strtolower(trim($url));
        if ($u === '') {
            return '';
        }
        $u = preg_replace('/[?#].*$/', '', $u) ?? $u;     // drop query + fragment
        $u = preg_replace('#^https?://#', '', $u) ?? $u;  // drop scheme
        $u = preg_replace('#^www\.#', '', $u) ?? $u;      // drop www.
        return rtrim($u, '/');
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
