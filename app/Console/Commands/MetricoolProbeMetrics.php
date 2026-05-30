<?php

namespace App\Console\Commands;

use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;

/**
 * VERIFICATION PROBE #1 — Metrics field audit (the gate that decides the switch).
 *
 * The entire reason to consider Metricool over Blotato is that Metricool reads
 * per-post analytics directly, while Blotato's analytics backend is dormant
 * (BlotatoMetricsCollector 404s for every post). Before we write a real
 * MetricoolMetricsCollector, this probe answers ONE question against the LIVE
 * API: for a connected IG + TikTok + LinkedIn brand, which of OUR post_metrics
 * columns actually populate?
 *
 * It does NOT guess field names from docs — it pulls the real analytics body
 * per network, flattens it, and reports which of our 10 typed counters
 * (impressions, reach, likes, comments, shares, saves, video_views,
 * profile_visits, url_clicks, engagement_rate) have a matching field present.
 *
 * Truthfulness Contract: a field that isn't in Metricool's response is reported
 * as MISSING (→ would store NULL), never assumed-zero.
 *
 * Usage:
 *   php artisan metricool:probe-metrics --blog=123
 *   php artisan metricool:probe-metrics --blog=123 --networks=instagram,tiktok,linkedin
 *   php artisan metricool:probe-metrics --blog=123 --days=30 --raw
 *
 * No-ops cleanly (clear message, success exit) when Metricool isn't configured,
 * so this can sit in CI/scheduler before credentials are provisioned.
 */
class MetricoolProbeMetrics extends Command
{
    protected $signature = 'metricool:probe-metrics
                            {--blog= : Metricool blogId (brand) to probe; omit to list brands and exit}
                            {--networks=instagram,tiktok,linkedin : comma-separated networks to test}
                            {--days=30 : look-back window in days}
                            {--raw : dump the raw first-post payload per network for field discovery}';

    protected $description = 'PROBE: pull live Metricool per-post analytics and map fields onto our post_metrics columns.';

    /**
     * Our typed post_metrics counters → the Metricool field-name candidates we
     * expect to satisfy each. Probing reports a HIT when ANY candidate is found
     * in the flattened response, and prints which candidate matched.
     *
     * @var array<string, array<int,string>>
     */
    private const COLUMN_CANDIDATES = [
        'impressions' => ['impressions', 'impressionCount', 'imps', 'views', 'viewCount'],
        'reach' => ['reach', 'reachCount', 'uniqueImpressions', 'accountsReached'],
        'likes' => ['likes', 'likeCount', 'reactions', 'favorites', 'diggCount'],
        'comments' => ['comments', 'commentCount', 'replies'],
        'shares' => ['shares', 'shareCount', 'retweets', 'reposts', 'repostCount'],
        'saves' => ['saves', 'saved', 'saveCount', 'bookmarks', 'collectCount'],
        'video_views' => ['videoViews', 'video_views', 'plays', 'playCount', 'views', 'watchTime'],
        'profile_visits' => ['profileVisits', 'profile_visits', 'profileViews', 'profileActivity'],
        'url_clicks' => ['urlClicks', 'url_clicks', 'linkClicks', 'websiteClicks', 'clicks'],
        'engagement_rate' => ['engagement', 'engagementRate', 'engagement_rate', 'interactions'],
    ];

    public function handle(): int
    {
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            $this->warn('Metricool is not configured (METRICOOL_API_TOKEN / METRICOOL_USER_ID empty or unresolved).');
            $this->line('Provision the token in Infisical and set the user id, then re-run. Skipping cleanly.');
            return self::SUCCESS;
        }

        // No --blog → list brands so the operator can pick a blogId.
        $blog = (int) $this->option('blog');
        if ($blog <= 0) {
            return $this->listBrandsAndExit($client);
        }

        $networks = collect(explode(',', (string) $this->option('networks')))
            ->map(fn ($n) => strtolower(trim($n)))
            ->filter()
            ->values()
            ->all();
        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();

        $this->info("Probing Metricool analytics for blogId={$blog}, window {$from}..{$to}");
        $this->newLine();

        $verdict = []; // network => [column => matchedCandidate|null]

        foreach ($networks as $network) {
            $this->line("── <fg=cyan>{$network}</> ──");
            try {
                $result = $client->postAnalytics($blog, $network, $from, $to);
            } catch (\Throwable $e) {
                $this->error("  request failed: " . $e->getMessage());
                $verdict[$network] = ['_error' => $e->getMessage()];
                $this->newLine();
                continue;
            }

            if (! $result['found']) {
                $this->warn("  HTTP 404 — analytics not available for {$network} on this plan/brand.");
                $verdict[$network] = ['_unavailable' => true];
                $this->newLine();
                continue;
            }

            $posts = $this->extractPosts($result['body']);
            if (empty($posts)) {
                $this->warn("  Reached the endpoint but it returned 0 posts in this window. "
                    . "Try a wider --days or a brand with recent posts.");
                $verdict[$network] = ['_no_posts' => true];
                $this->newLine();
                continue;
            }

            $this->line("  posts in window: <fg=green>" . count($posts) . "</>");

            // Flatten the FIRST post (one level deep into nested metric objects)
            // and probe each of our columns against the candidate field names.
            $flat = $this->flatten($posts[0]);
            if ($this->option('raw')) {
                $this->line('  <fg=gray>raw first-post fields:</> ' . implode(', ', array_keys($flat)));
            }

            $row = [];
            foreach (self::COLUMN_CANDIDATES as $column => $candidates) {
                $matched = null;
                foreach ($candidates as $cand) {
                    // case-insensitive key match on the flattened map
                    foreach ($flat as $k => $v) {
                        if (strcasecmp($k, $cand) === 0 && $v !== null && $v !== '') {
                            $matched = $cand;
                            break 2;
                        }
                    }
                }
                $row[$column] = $matched;
            }
            $verdict[$network] = $row;

            // Per-network table: column | status | matched field
            $tableRows = [];
            foreach ($row as $column => $matched) {
                $tableRows[] = [
                    $column,
                    $matched ? '<fg=green>HIT</>' : '<fg=red>missing → NULL</>',
                    $matched ?? '—',
                ];
            }
            $this->table(['post_metrics column', 'status', 'Metricool field'], $tableRows);
            $this->newLine();
        }

        return $this->printSummary($verdict, $networks);
    }

    private function listBrandsAndExit(MetricoolClient $client): int
    {
        $this->info('No --blog given. Listing brands so you can pick a blogId:');
        try {
            $brands = $client->listBrands();
        } catch (\Throwable $e) {
            $this->error('listBrands failed: ' . $e->getMessage());
            return self::FAILURE;
        }
        if (empty($brands)) {
            $this->warn('Account has no brands. Create one in Metricool and connect IG/TikTok/LinkedIn first.');
            return self::SUCCESS;
        }
        $rows = [];
        foreach ($brands as $b) {
            $rows[] = [
                $b['id'] ?? $b['blogId'] ?? '?',
                $b['label'] ?? $b['title'] ?? $b['name'] ?? '(unnamed)',
            ];
        }
        $this->table(['blogId', 'brand'], $rows);
        $this->line('Re-run with --blog=<id> to probe one brand\'s analytics.');
        return self::SUCCESS;
    }

    /**
     * Metricool analytics responses vary: a bare list, {data:[…]}, {posts:[…]},
     * or {timeline:[…]}. Pull whichever list of post objects is present.
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
     * Flatten one post object one level deep, so nested metric blocks like
     * {"metrics":{"likes":3}} expose "likes" as a top-level key. Scalar values
     * win over nested when keys collide (keep the simplest).
     *
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>
     */
    private function flatten(array $post): array
    {
        $flat = [];
        foreach ($post as $k => $v) {
            if (is_array($v) && ! array_is_list($v)) {
                foreach ($v as $k2 => $v2) {
                    if (! is_array($v2) && ! array_key_exists($k2, $flat)) {
                        $flat[$k2] = $v2;
                    }
                }
            } elseif (! is_array($v)) {
                $flat[$k] = $v;
            }
        }
        return $flat;
    }

    /**
     * @param  array<string,array<string,mixed>>  $verdict
     * @param  array<int,string>  $networks
     */
    private function printSummary(array $verdict, array $networks): int
    {
        $this->info('═══ SUMMARY — does Metricool populate our dashboard? ═══');

        $columns = array_keys(self::COLUMN_CANDIDATES);
        $header = array_merge(['network'], array_map(fn ($c) => substr($c, 0, 6), $columns));
        $rows = [];
        $coreOk = true; // impressions+reach+likes+comments+shares across all networks

        foreach ($networks as $network) {
            $row = [$network];
            $v = $verdict[$network] ?? [];
            if (isset($v['_unavailable']) || isset($v['_error']) || isset($v['_no_posts'])) {
                $reason = isset($v['_unavailable']) ? '404 unavailable'
                    : (isset($v['_no_posts']) ? 'no posts in window' : 'request error');
                $rows[] = array_merge([$network], array_fill(0, count($columns), '·'));
                $rows[] = array_merge(['  ↳ ' . $reason], array_fill(0, count($columns), ''));
                $coreOk = false;
                continue;
            }
            foreach ($columns as $c) {
                $row[] = ! empty($v[$c]) ? '✓' : '✗';
            }
            $rows[] = $row;

            foreach (['impressions', 'reach', 'likes', 'comments', 'shares'] as $core) {
                if (empty($v[$core])) {
                    $coreOk = false;
                }
            }
        }

        $this->table($header, $rows);

        $this->newLine();
        if ($coreOk) {
            $this->info('VERDICT: ✓ Core engagement counters (impressions/reach/likes/comments/shares) '
                . 'populate on ALL probed networks. The metrics-field gate PASSES — proceed to the '
                . 'publishing-parity audit (metricool:probe-publish), then write MetricoolMetricsCollector.');
            return self::SUCCESS;
        }

        $this->warn('VERDICT: ✗ At least one core counter is missing on at least one network '
            . '(see ✗ / · above). The metrics-field gate is NOT a clean pass. Decide per network: '
            . 'accept the gap (store NULL — Truthfulness Contract allows it) or keep CSV/Meta-Graph '
            . 'for that platform. Do NOT silently ship a half-empty dashboard.');
        return self::SUCCESS; // a soft "review needed", not a hard failure
    }
}
