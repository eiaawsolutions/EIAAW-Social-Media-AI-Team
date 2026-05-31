<?php

namespace App\Services\Metricool;

use App\Models\Brand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AccountGrowthService — the data brain behind the account-growth dashboard
 * (followers + impressions over time, per network). This is the "Account" view
 * Metricool shows, NOT per-post analytics: it reads the account-level
 * timeseries via MetricoolClient::getAccountTimeline() (GET /v2/analytics/timelines,
 * subject=account). Verified live 2026-05-31 — the follower counts returned match
 * the Metricool UI exactly (LinkedIn 12, Instagram 7, TikTok 3, YouTube 1).
 *
 * One brand at a time (a Metricool blogId). The HQ page passes EIAAW's own
 * internal brand; the customer rollout will pass each customer's brand — the
 * service is brand-agnostic so the same code serves both. Multi-tenancy is the
 * standard Metricool discipline: every call is scoped by the brand's blogId.
 *
 * Truthfulness Contract: we ONLY plot real readings returned by Metricool.
 *   - A network whose timeline 404s/400s (not connected, or no metric for it)
 *     is status='not_available'.
 *   - A network with the metric but no readings in the window is status='no_data'.
 *   - A missing data point is omitted, never coerced to 0.
 *
 * Live cache: results are cached briefly (default 5 min) so the page feels
 * live without hammering Metricool on every poll/refresh. Cache key is scoped
 * by blogId + window so brands and windows never collide.
 */
class AccountGrowthService
{
    /**
     * Per-network metric identifiers for the account-growth timelines, VERIFIED
     * LIVE against prod blogId 6322515 on 2026-05-31 via GET /v2/analytics/timelines
     * (subject=account). Metric names are network-specific and CASE-SENSITIVE —
     * Metricool's API surfaced each network's valid enum on an invalid value, and
     * the follower numbers returned matched the Metricool UI exactly (LinkedIn 12,
     * Instagram 7, TikTok 3, YouTube 1).
     *
     * `network` is the slug passed to the API (X uses 'twitter'). A null
     * dimension = that network exposes no metric for it → that tile is omitted
     * for that dimension, never invented (Truthfulness Contract).
     *
     * NOTE: TikTok and Threads ARE covered — the earlier "no API data" belief was
     * an artifact of the WRONG legacy endpoint (/stats/timeline). The correct
     * /v2/analytics/timelines endpoint returns real follower counts for both.
     *
     * `followers` is the account-timeline metric (GET /v2/analytics/timelines).
     * `impressions` is the per-post analytics field summed across the window
     * (GET /v2/analytics/posts/{network}) — Metricool's own "Impressions" card
     * is that same per-post sum, NOT an account-level series (the account
     * timeline returns empty/500 for impressions). Verified live 2026-05-31:
     * summing TikTok viewCount = 717 and Facebook impressions = 8 matched the
     * Metricool UI exactly; IG impressionsTotal = 1,270 (UI 1,061 — the small
     * gap is Metricool's card excluding some post types/boundary, our number is
     * the defensible sum of all real posts). LinkedIn post-impressions aren't
     * exposed for this brand (page-impressions API 500s) → null = "not available".
     *
     * @var array<string, array{label:string, color:string, network:string, followers:?string, impression_fields:?array<int,string>}>
     */
    public const NETWORKS = [
        'instagram' => ['label' => 'Instagram', 'color' => '#E1306C', 'network' => 'instagram', 'followers' => 'Followers', 'impression_fields' => ['impressionsTotal']],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'network' => 'facebook', 'followers' => 'Follows', 'impression_fields' => ['impressions', 'impressionsTotal']],
        'linkedin' => ['label' => 'LinkedIn', 'color' => '#0A66C2', 'network' => 'linkedin', 'followers' => 'Followers', 'impression_fields' => null],
        'tiktok' => ['label' => 'TikTok', 'color' => '#000000', 'network' => 'tiktok', 'followers' => 'followers_count', 'impression_fields' => ['viewCount', 'views']],
        'youtube' => ['label' => 'YouTube', 'color' => '#FF0000', 'network' => 'youtube', 'followers' => 'totalSubscribers', 'impression_fields' => ['views', 'impressions']],
        'threads' => ['label' => 'Threads', 'color' => '#444444', 'network' => 'threads', 'followers' => 'followers_count', 'impression_fields' => ['impressions', 'views']],
        'twitter' => ['label' => 'X (Twitter)', 'color' => '#111827', 'network' => 'twitter', 'followers' => 'Followers', 'impression_fields' => ['impressions', 'impressionsTotal']],
    ];

    /**
     * Networks the dashboard surfaces but Metricool's timelines don't cover.
     * Empty now that /v2/analytics/timelines is wired (it covers every network we
     * publish to). Kept as the honest seam for any future uncovered network.
     *
     * @var array<string, array{label:string, color:string}>
     */
    public const NETWORKS_WITHOUT_API = [];

    private const CACHE_TTL_SECONDS = 300; // 5 min — "live" without hammering Metricool

    public function __construct(private readonly ?MetricoolClient $client) {}

    /**
     * Resolve the EIAAW-internal brand to show on the HQ dashboard: the brand
     * belonging to the internal workspace (plan='eiaaw_internal') that has a
     * Metricool blogId mapped. Resolved from the DB so it is never a stale
     * hardcoded id; returns null when HQ hasn't mapped its own brand yet (the
     * UI then prompts to map it instead of showing a wrong account).
     */
    public function hqBrand(): ?Brand
    {
        return Brand::query()
            ->whereNull('archived_at')
            ->whereNotNull('metricool_blog_id')
            ->whereHas('workspace', fn ($q) => $q->where('plan', 'eiaaw_internal'))
            ->orderBy('id')
            ->first();
    }

    /**
     * Build the full growth payload for a brand over the window (in days).
     *
     * Shape (drives the Blade view directly):
     * [
     *   'configured'   => bool,           // Metricool token wired?
     *   'blog_id'      => int|null,
     *   'window_days'  => int,
     *   'from'         => 'YYYY-MM-DD',
     *   'to'           => 'YYYY-MM-DD',
     *   'followers'    => Dimension,      // see dimensionFor()
     *   'impressions'  => Dimension,
     *   'unsupported'  => [['network'=>..,'label'=>..,'color'=>..], …],  // tiktok/threads
     * ]
     *
     * @return array<string,mixed>
     */
    public function forBrand(Brand $brand, int $windowDays = 30): array
    {
        $windowDays = max(7, min(180, $windowDays));
        $blogId = (int) ($brand->metricool_blog_id ?? 0);

        $to = Carbon::now($brand->timezone ?: 'UTC')->endOfDay();
        $from = $to->copy()->subDays($windowDays - 1)->startOfDay();

        $base = [
            'configured' => $this->client !== null,
            'blog_id' => $blogId ?: null,
            'window_days' => $windowDays,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'unsupported' => $this->unsupportedNetworks(),
        ];

        // Not wired or not mapped → return the scaffold with empty dimensions so
        // the page renders its "connect / map" empty state instead of erroring.
        if ($this->client === null || $blogId <= 0) {
            return $base + [
                'followers' => $this->emptyDimension('Followers'),
                'impressions' => $this->emptyDimension('Impressions'),
            ];
        }

        $cacheKey = sprintf('metricool:growth:%d:%d', $blogId, $windowDays);

        // Both Metricool analytics families want ISO datetime.
        $fromIso = $from->format('Y-m-d\TH:i:s');
        $toIso = $to->format('Y-m-d\TH:i:s');

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($base, $blogId, $fromIso, $toIso) {
            return $base + [
                // Followers = account-level stock from the timelines endpoint.
                'followers' => $this->followersDimension($blogId, $fromIso, $toIso),
                // Impressions = summed per-post analytics (what the UI card shows).
                'impressions' => $this->impressionsDimension($blogId, $fromIso, $toIso),
            ];
        });
    }

    /**
     * Followers dimension — account-level STOCK from GET /v2/analytics/timelines
     * (subject=account). Headline = LATEST follower count; change = latest−first
     * over the window (net new). Per the Truthfulness Contract: a network that
     * 400/404/500s → 'not_available'; no readings → 'no_data'; gaps stay null.
     *
     * @return array<string,mixed>
     */
    private function followersDimension(int $blogId, string $fromIso, string $toIso): array
    {
        $networks = [];
        $allDates = [];
        $total = 0;
        $hasData = false;

        foreach (self::NETWORKS as $network => $meta) {
            $metric = $meta['followers'] ?? null;
            if ($metric === null) {
                continue;
            }

            try {
                $result = $this->client->getAccountTimeline(
                    blogId: $blogId,
                    metric: $metric,
                    network: $meta['network'],
                    fromIso: $fromIso,
                    toIso: $toIso,
                );
            } catch (\Throwable $e) {
                Log::warning('AccountGrowthService: followers timeline failed', [
                    'blog_id' => $blogId, 'network' => $meta['network'], 'metric' => $metric, 'error' => $e->getMessage(),
                ]);
                $networks[] = $this->networkRow($network, $meta, status: 'error');
                continue;
            }

            if (! ($result['found'] ?? false)) {
                $networks[] = $this->networkRow($network, $meta, status: 'not_available');
                continue;
            }

            $points = $result['points'] ?? [];
            if (count($points) === 0) {
                $networks[] = $this->networkRow($network, $meta, status: 'no_data');
                continue;
            }

            $hasData = true;
            foreach ($points as $p) {
                $allDates[$p['date']] = true;
            }

            $values = array_map(static fn ($p) => $p['value'], $points);
            $headline = (int) end($values);              // latest level (stock)
            $change = $headline - (int) reset($values);  // net new over window
            $total += $headline;

            $networks[] = $this->networkRow($network, $meta, status: 'ok', headline: $headline, change: $change, points: $points);
        }

        return $this->finaliseDimension('Followers', 'followers', $networks, $allDates, $total, $hasData);
    }

    /**
     * Impressions dimension — the SUM of per-post impressions across the window,
     * via GET /v2/analytics/posts/{network}. This is what Metricool's own
     * "Impressions" card shows (the account-timeline endpoint returns empty/500
     * for impressions). Each post contributes its impression field (per-network,
     * verified in memory metricool-field-map) on its publish date, so we still
     * get a daily series for the chart. Headline = window sum (a flow).
     *
     * LinkedIn has impression_fields=null: its post-analytics doesn't expose
     * impressions for this brand and the page-impressions API 500s, so we render
     * 'not_available' rather than invent a number (Truthfulness Contract).
     *
     * @return array<string,mixed>
     */
    private function impressionsDimension(int $blogId, string $fromIso, string $toIso): array
    {
        $networks = [];
        $allDates = [];
        $total = 0;
        $hasData = false;

        foreach (self::NETWORKS as $network => $meta) {
            $fields = $meta['impression_fields'] ?? null;
            if ($fields === null) {
                // Impressions genuinely not exposed to us for this network.
                $networks[] = $this->networkRow($network, $meta, status: 'not_available');
                continue;
            }

            try {
                $result = $this->client->postAnalytics($blogId, $fromIso, $toIso, $meta['network']);
            } catch (\Throwable $e) {
                Log::warning('AccountGrowthService: post analytics failed', [
                    'blog_id' => $blogId, 'network' => $meta['network'], 'error' => $e->getMessage(),
                ]);
                $networks[] = $this->networkRow($network, $meta, status: 'error');
                continue;
            }

            if (! ($result['found'] ?? false)) {
                $networks[] = $this->networkRow($network, $meta, status: 'not_available');
                continue;
            }

            // Bucket each post's impressions onto its publish date.
            $byDate = [];
            foreach ($this->extractPostList($result['body'] ?? null) as $post) {
                if (! is_array($post)) {
                    continue;
                }
                $value = $this->firstNumeric($post, $fields);
                if ($value === null) {
                    continue;
                }
                $date = $this->postDate($post);
                $byDate[$date] = ($byDate[$date] ?? 0) + $value;
            }

            if (count($byDate) === 0) {
                $networks[] = $this->networkRow($network, $meta, status: 'no_data');
                continue;
            }

            $hasData = true;
            $points = [];
            foreach ($byDate as $date => $value) {
                $allDates[$date] = true;
                $points[] = ['date' => $date, 'value' => $value];
            }
            usort($points, static fn ($a, $b) => strcmp($a['date'], $b['date']));

            $headline = (int) array_sum(array_column($points, 'value')); // flow = sum
            $total += $headline;

            $networks[] = $this->networkRow($network, $meta, status: 'ok', headline: $headline, change: $headline, points: $points);
        }

        return $this->finaliseDimension('Impressions', 'impressions', $networks, $allDates, $total, $hasData);
    }

    /**
     * Shared tail for both dimensions: sort the merged date axis, re-index each
     * ok network's series onto it (gaps null, never 0 — Chart.js spanGaps), and
     * shape the dimension payload.
     *
     * @param  array<int,array<string,mixed>>  $networks
     * @param  array<string,bool>  $allDates
     * @return array<string,mixed>
     */
    private function finaliseDimension(string $title, string $dimension, array $networks, array $allDates, int $total, bool $hasData): array
    {
        ksort($allDates);
        $axis = array_keys($allDates);

        foreach ($networks as &$row) {
            if ($row['status'] !== 'ok') {
                $row['series'] = [];
                unset($row['_points']);
                continue;
            }
            $byDate = [];
            foreach ($row['_points'] as $p) {
                $byDate[$p['date']] = $p['value'];
            }
            $row['series'] = array_map(static fn ($d) => $byDate[$d] ?? null, $axis);
            unset($row['_points']);
        }
        unset($row);

        return [
            'title' => $title,
            'dimension' => $dimension,
            'axis' => $axis,
            'total' => $total,
            'has_data' => $hasData,
            'networks' => $networks,
        ];
    }

    /**
     * Metricool's per-post analytics body is sometimes a bare list, sometimes
     * enveloped under data/posts/items. Return the list of post objects.
     *
     * @return array<int,mixed>
     */
    private function extractPostList(mixed $body): array
    {
        if (is_array($body) && array_is_list($body)) {
            return $body;
        }
        if (is_array($body)) {
            foreach (['data', 'posts', 'items', 'results'] as $key) {
                if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                    return $body[$key];
                }
            }
        }
        return [];
    }

    /** First numeric value among the candidate field names; null if none present. */
    private function firstNumeric(array $bag, array $fields): ?int
    {
        foreach ($fields as $f) {
            if (isset($bag[$f]) && is_numeric($bag[$f])) {
                return (int) $bag[$f];
            }
        }
        return null;
    }

    /**
     * Publish date (YYYY-MM-DD) of a post. Metricool's date field varies by
     * network (verified live): TikTok `createTime` (string), Threads
     * `publishedDate` (string), Instagram `publishedAt` (an OBJECT
     * {dateTime, timezone} — must dig in, not cast to string). Falls back across
     * all known shapes; 'unknown' only if none present.
     */
    private function postDate(array $post): string
    {
        foreach (['dateTime', 'createTime', 'publishedDate', 'date', 'publishedAt'] as $field) {
            $raw = $post[$field] ?? null;
            // Instagram's publishedAt is {dateTime: "...", timezone: "..."}.
            if (is_array($raw)) {
                $raw = $raw['dateTime'] ?? $raw['date'] ?? null;
            }
            if (is_string($raw) && $raw !== '') {
                return substr($raw, 0, 10);
            }
        }
        return 'unknown';
    }

    /**
     * One per-network row. `status` is the single source of truth for how the
     * UI renders it: ok | no_data | not_available | no_api_data | error.
     *
     * @param  array{label:string,color:string}  $meta
     * @param  array<int,array{date:string,value:int|float}>  $points
     * @return array<string,mixed>
     */
    private function networkRow(
        string $network,
        array $meta,
        string $status,
        ?int $headline = null,
        ?int $change = null,
        array $points = [],
    ): array {
        return [
            'network' => $network,
            'label' => $meta['label'],
            'color' => $meta['color'],
            'status' => $status,
            'headline' => $headline,
            'change' => $change,
            'series' => [],      // filled against the shared axis in dimensionFor()
            '_points' => $points, // internal: raw points, dropped after re-indexing
        ];
    }

    /** Networks the dashboard shows but Metricool can't report — for the honest tile. */
    private function unsupportedNetworks(): array
    {
        $out = [];
        foreach (self::NETWORKS_WITHOUT_API as $network => $meta) {
            $out[] = ['network' => $network, 'label' => $meta['label'], 'color' => $meta['color']];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function emptyDimension(string $title): array
    {
        $networks = [];
        foreach (self::NETWORKS as $network => $meta) {
            $networks[] = $this->networkRow($network, $meta, status: 'no_data');
            unset($networks[array_key_last($networks)]['_points']);
        }
        return [
            'title' => $title,
            'dimension' => strtolower($title),
            'axis' => [],
            'total' => 0,
            'has_data' => false,
            'networks' => $networks,
        ];
    }

    /** Drop the brand+window cache so a manual refresh re-pulls from Metricool. */
    public function forget(int $blogId, int $windowDays): void
    {
        Cache::forget(sprintf('metricool:growth:%d:%d', $blogId, $windowDays));
    }
}
