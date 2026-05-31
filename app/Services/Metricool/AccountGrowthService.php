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
     * @var array<string, array{label:string, color:string, network:string, followers:?string, impressions:?string}>
     */
    public const NETWORKS = [
        'instagram' => ['label' => 'Instagram', 'color' => '#E1306C', 'network' => 'instagram', 'followers' => 'Followers', 'impressions' => 'impressions'],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'network' => 'facebook', 'followers' => 'Follows', 'impressions' => 'pageImpressions'],
        'linkedin' => ['label' => 'LinkedIn', 'color' => '#0A66C2', 'network' => 'linkedin', 'followers' => 'Followers', 'impressions' => null],
        'tiktok' => ['label' => 'TikTok', 'color' => '#000000', 'network' => 'tiktok', 'followers' => 'followers_count', 'impressions' => 'video_views'],
        'youtube' => ['label' => 'YouTube', 'color' => '#FF0000', 'network' => 'youtube', 'followers' => 'totalSubscribers', 'impressions' => 'views'],
        'threads' => ['label' => 'Threads', 'color' => '#444444', 'network' => 'threads', 'followers' => 'followers_count', 'impressions' => 'views'],
        'twitter' => ['label' => 'X (Twitter)', 'color' => '#111827', 'network' => 'twitter', 'followers' => 'Followers', 'impressions' => null],
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

        // /v2/analytics/timelines wants ISO datetime, not the legacy YYYYMMDD.
        $fromIso = $from->format('Y-m-d\TH:i:s');
        $toIso = $to->format('Y-m-d\TH:i:s');

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($base, $blogId, $fromIso, $toIso) {
            return $base + [
                'followers' => $this->dimensionFor('Followers', 'followers', $blogId, $fromIso, $toIso),
                'impressions' => $this->dimensionFor('Impressions', 'impressions', $blogId, $fromIso, $toIso),
            ];
        });
    }

    /**
     * Build one dimension (Followers or Impressions): the per-network series,
     * each network's headline number + change, the cross-network total, and a
     * merged date axis for the combined chart.
     *
     * For Followers, the headline is the LATEST follower count and "change" is
     * latest−first over the window (net new followers). For Impressions, the
     * headline is the SUM over the window (impressions are a flow, not a stock),
     * and we keep the daily series for the area chart.
     *
     * @return array{
     *   title:string, dimension:string, axis:array<int,string>,
     *   total:int, networks:array<int,array<string,mixed>>, has_data:bool
     * }
     */
    private function dimensionFor(string $title, string $dimension, int $blogId, string $fromIso, string $toIso): array
    {
        $isStock = $dimension === 'followers'; // stock = level; flow = summed

        $networks = [];
        $allDates = [];
        $total = 0;
        $hasData = false;

        foreach (self::NETWORKS as $network => $meta) {
            $metric = $meta[$dimension] ?? null;

            // This network exposes no metric for this dimension (e.g. LinkedIn/X
            // have no account impressions timeline) → omit its tile here rather
            // than show a misleading empty one.
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
                Log::warning('AccountGrowthService: timeline fetch failed', [
                    'blog_id' => $blogId, 'network' => $meta['network'], 'metric' => $metric, 'error' => $e->getMessage(),
                ]);
                $networks[] = $this->networkRow($network, $meta, status: 'error');
                continue;
            }

            if (! ($result['found'] ?? false)) {
                // 404/400/500 — network not connected, or no such metric for it.
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
            $headline = $isStock ? (int) end($values) : (int) array_sum($values);
            $first = (int) reset($values);
            $change = $isStock ? ($headline - $first) : (int) array_sum($values);

            $total += $headline;

            $networks[] = $this->networkRow($network, $meta, status: 'ok', headline: $headline, change: $change, points: $points);
        }

        ksort($allDates);
        $axis = array_keys($allDates);

        // Re-index each ok network's series onto the shared axis (gaps stay null,
        // not 0 — Chart.js draws spanGaps; the contract forbids fabricated zeros).
        foreach ($networks as &$row) {
            if ($row['status'] !== 'ok') {
                $row['series'] = [];
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
