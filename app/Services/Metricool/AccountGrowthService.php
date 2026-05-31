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
 * timeseries via MetricoolClient::getAccountTimeline() (GET /stats/timeline/…).
 *
 * One brand at a time (a Metricool blogId). The HQ page passes EIAAW's own
 * internal brand; the customer rollout will pass each customer's brand — the
 * service is brand-agnostic so the same code serves both. Multi-tenancy is the
 * standard Metricool discipline: every call is scoped by the brand's blogId.
 *
 * Truthfulness Contract: we ONLY plot real readings returned by Metricool.
 *   - Networks Metricool has NO timeline metric for (TikTok, Threads) are
 *     returned with status='no_api_data' and an empty series — the UI greys
 *     them out and explains the gap; it never fabricates a flat/zero line.
 *   - A network whose endpoint 404s (not connected / not on plan) is
 *     status='not_available'.
 *   - A missing data point is omitted, never coerced to 0.
 *
 * Live cache: results are cached briefly (default 5 min) so the page feels
 * live without hammering Metricool on every poll/refresh. Cache key is scoped
 * by blogId + window so brands and windows never collide.
 */
class AccountGrowthService
{
    /**
     * Per-network metric identifiers, verified against Metricool's Swagger
     * (app.metricool.com/api/swagger.json, 2026-05-31). `followers` and
     * `impressions` are the metric path-segments /stats/timeline/{metric} wants.
     *
     * A null value = Metricool exposes no timeline metric for that
     * (network, dimension) pair → rendered as "no API data", never invented.
     *
     * Networks intentionally ABSENT (tiktok, threads): Metricool has NO account
     * timeline endpoint for them at all — they're added by networksWithoutApi()
     * so the UI can show them honestly as unsupported.
     *
     * @var array<string, array{label:string, color:string, followers:?string, impressions:?string}>
     */
    public const NETWORKS = [
        'instagram' => ['label' => 'Instagram', 'color' => '#E1306C', 'followers' => 'igFollowers', 'impressions' => 'igimpressions'],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'followers' => 'fbFollows', 'impressions' => 'pageImpressions'],
        'linkedin' => ['label' => 'LinkedIn', 'color' => '#0A66C2', 'followers' => 'inFollowers', 'impressions' => 'inCompanyImpressions'],
        'twitter' => ['label' => 'X (Twitter)', 'color' => '#111827', 'followers' => 'twitterFollowers', 'impressions' => null],
        'youtube' => ['label' => 'YouTube', 'color' => '#FF0000', 'followers' => 'ytsubscribers', 'impressions' => 'ytviews'],
    ];

    /** Networks Metricool's API has no account-timeline for — shown honestly as unsupported. */
    public const NETWORKS_WITHOUT_API = [
        'tiktok' => ['label' => 'TikTok', 'color' => '#000000'],
        'threads' => ['label' => 'Threads', 'color' => '#444444'],
    ];

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

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($base, $blogId, $from, $to) {
            return $base + [
                'followers' => $this->dimensionFor('Followers', 'followers', $blogId, $from, $to),
                'impressions' => $this->dimensionFor('Impressions', 'impressions', $blogId, $from, $to),
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
    private function dimensionFor(string $title, string $dimension, int $blogId, Carbon $from, Carbon $to): array
    {
        $isStock = $dimension === 'followers'; // stock = level; flow = summed
        $startYmd = $from->format('Ymd');
        $endYmd = $to->format('Ymd');

        $networks = [];
        $allDates = [];
        $total = 0;
        $hasData = false;

        foreach (self::NETWORKS as $network => $meta) {
            $metric = $meta[$dimension] ?? null;

            // Metricool has no metric for this (network, dimension) → honest gap.
            if ($metric === null) {
                $networks[] = $this->networkRow($network, $meta, status: 'no_api_data');
                continue;
            }

            try {
                $result = $this->client->getAccountTimeline($blogId, $metric, $startYmd, $endYmd);
            } catch (\Throwable $e) {
                Log::warning('AccountGrowthService: timeline fetch failed', [
                    'blog_id' => $blogId, 'metric' => $metric, 'error' => $e->getMessage(),
                ]);
                $networks[] = $this->networkRow($network, $meta, status: 'error');
                continue;
            }

            if (! ($result['found'] ?? false)) {
                // 404 — network not connected / metric not on plan.
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
