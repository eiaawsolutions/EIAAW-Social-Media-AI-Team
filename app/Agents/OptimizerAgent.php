<?php

namespace App\Agents;

use App\Models\Brand;
use App\Models\PostMetric;
use App\Models\StrategistRecommendation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the last N days of post_metrics for a brand, computes which pillar /
 * format / platform combinations are outperforming, and writes a
 * StrategistRecommendation row that the Strategist consumes on the next
 * calendar build to bias the next month toward what's working.
 *
 * Scoring (v2, 2026-07-31). Every post is reduced to a value in [0,1] that is
 * its MID-RANK PERCENTILE **within its own platform**, blending engagement
 * (weight 0.7) and impressions (weight 0.3). Dimension mixes are the MEAN of
 * those values per pillar/format.
 *
 * This replaced `impressions + 5*likes + 10*comments + 20*shares + 30*saves`
 * summed per dimension, which a prod audit showed was measuring the wrong
 * thing on all three axes:
 *
 *   1. The raw impressions term swamped every engagement term. At real volumes
 *      (305 measured posts → 12,526 impressions but only 384 engagements) the
 *      top-ranked post fed to the Strategist was a LinkedIn carousel with 951
 *      impressions and ZERO engagement. Re-ranking the same posts by
 *      engagement produced 0/20 overlap with the old top 20.
 *   2. Impressions are not commensurable across networks (median: TikTok 111,
 *      LinkedIn 37, Instagram 13, Threads 9, Facebook 1) — yet dimension
 *      scores were summed ACROSS platforms, so the "winning pillar" really
 *      meant "the pillar that happened to land on LinkedIn".
 *   3. Summing rather than averaging meant volume bought the win: post more
 *      carousels and carousel leads regardless of per-post quality. The
 *      recommendation was a mirror of past behaviour, not a signal.
 *
 * Percentiles also remove the need for a minimum-impressions floor: ranking by
 * a raw engagement RATE puts 1-impression posts on top, ranking by percentile
 * of the raw counts does not.
 *
 * Platform weighting is a separate, cross-platform question — see
 * platformViability(). A surface that cannot deliver reach at all (Facebook:
 * 68 posts → 139 total impressions) is driven to a token exploration weight
 * instead of keeping an equal share of the cadence.
 *
 * v1: pure deterministic math (no LLM). The plain-English `summary` is
 * generated locally too; v1.1 layer adds an LLM "what's working" narrative.
 *
 * Optional input:
 *   - window_days (int, default 30)
 */
class OptimizerAgent extends BaseAgent
{
    public function role(): string { return 'optimizer'; }
    public function promptVersion(): string { return 'optimizer.v1.0'; }

    private const DEFAULT_WINDOW_DAYS = 30;

    /** Used to balance "follow what's working" vs "keep variety". 0.5 is moderate bias. */
    private const RECOMMENDATION_WEIGHT = 0.5;

    /** Minimum posts in the window before we generate a recommendation. */
    private const MIN_POSTS_FOR_RECOMMENDATION = 3;

    /** Strategist's defaults — re-applied as the floor for every dimension. */
    private const DEFAULT_PILLAR_MIX = [
        'educational' => 0.30, 'community' => 0.25, 'promotional' => 0.15,
        'behind_the_scenes' => 0.15, 'thought_leadership' => 0.15,
    ];

    private const DEFAULT_FORMAT_MIX = [
        'single_image' => 0.30, 'carousel' => 0.30, 'reel' => 0.20,
        'text_only' => 0.15, 'video' => 0.05,
    ];

    /**
     * Share of a post's value that comes from engagement vs raw reach.
     * Engagement is the scarce, intent-bearing signal and the thing the brand
     * actually wants; reach still counts, because a post nobody saw is not a
     * win either. Applied both per post and per platform.
     */
    private const ENGAGEMENT_WEIGHT = 0.7;

    /**
     * Dead-surface verdict. A platform is "dead" when we have enough posts to
     * judge AND its MEDIAN post reaches no more than a handful of people.
     *
     * Deliberately a REACH-only test: engagement cannot rescue a surface with
     * no distribution. Facebook's real shape — 68 posts, median 1 impression —
     * still produced a 4.32% engagement rate off 6 total interactions, which
     * would look healthy on any ratio-based test. It is not healthy.
     */
    private const DEAD_SURFACE_MIN_POSTS = 10;

    private const DEAD_SURFACE_MEDIAN_IMPRESSIONS = 3;

    /**
     * Weight a dead surface keeps. Never zero — the account may be fixed
     * (page boosted, connection re-pointed, restriction lifted) and the surface
     * needs a trickle of posts to prove it has recovered. Re-evaluated on every
     * run against a rolling window, so recovery is picked up automatically.
     */
    private const DEAD_SURFACE_WEIGHT = 0.02;

    /** Floor for any live platform, so one strong network can't take 100%. */
    private const MIN_PLATFORM_WEIGHT = 0.05;

    /**
     * Collapse verdict. A window median is backward-looking, so a surface that
     * was healthy for three weeks and then stopped delivering still reads as
     * healthy on the median alone — and would attract MORE cadence.
     *
     * Real case: TikTok held ~93-116 views per post through 2026-07-20, then
     * every post from 07-23 sat at 0-4. Old posts kept their views and every
     * post published fine, so this was new-upload suppression at the platform's
     * end — invisible to a median test (window median was still ~102) and
     * invisible to the publishing pipeline.
     *
     * A surface is COLLAPSED when its most recent posts have fallen to a small
     * fraction of what the same surface was doing earlier in the window. The
     * prior-median floor keeps this distinct from DEAD: a surface that never
     * delivered has no height to fall from and is dead, not collapsed.
     */
    private const COLLAPSE_RECENT_FRACTION = 0.3;

    private const COLLAPSE_MIN_RECENT_POSTS = 3;

    private const COLLAPSE_PRIOR_MEDIAN_FLOOR = 10;

    private const COLLAPSE_RATIO = 0.25;

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $windowDays = (int) ($input['window_days'] ?? self::DEFAULT_WINDOW_DAYS);
        $endsOn = Carbon::now();
        $startsOn = $endsOn->copy()->subDays($windowDays);

        $rows = $this->latestMetricPerPost($brand, $startsOn);

        if ($rows->count() < self::MIN_POSTS_FOR_RECOMMENDATION) {
            return AgentResult::fail(sprintf(
                'Not enough data: %d post(s) in %d-day window (need ≥%d). Publish more, then re-run.',
                $rows->count(),
                $windowDays,
                self::MIN_POSTS_FOR_RECOMMENDATION,
            ));
        }

        // One flattening pass feeds all three mixes so every dimension is
        // scored off the same per-post values.
        $posts = $this->toPlainPosts($rows);
        $values = self::normalisedPostValues($posts);

        $pillarMix = $this->blendMix(self::meanValueByDimension($posts, $values, 'pillar'), self::DEFAULT_PILLAR_MIX);
        $formatMix = $this->blendMix(self::meanValueByDimension($posts, $values, 'format'), self::DEFAULT_FORMAT_MIX);
        // Platform weighting is NOT blended toward an even split: an even floor
        // is exactly what kept a dead surface on a full share of the cadence.
        $platformMix = self::platformViability($posts);
        $surfaceHealth = self::surfaceHealth($posts);

        $topPerformers = $this->topN($rows, $values, 5);
        $summary = $this->buildSummary($rows, $pillarMix, $formatMix, $platformMix, $surfaceHealth);

        // Demote previous current row + write the new one.
        DB::transaction(function () use ($brand, $startsOn, $endsOn, $pillarMix, $formatMix, $platformMix, $topPerformers, $summary, $rows): void {
            StrategistRecommendation::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            StrategistRecommendation::create([
                'brand_id' => $brand->id,
                'is_current' => true,
                'window_starts_on' => $startsOn->toDateString(),
                'window_ends_on' => $endsOn->toDateString(),
                'pillar_mix' => $pillarMix,
                'format_mix' => $formatMix,
                'platform_mix' => $platformMix,
                'top_performers' => $topPerformers,
                'summary' => $summary,
                'post_count_in_window' => $rows->count(),
                'impressions_total' => (int) $rows->sum(fn ($r) => (int) ($r->impressions ?? 0)),
                'engagement_total' => (int) $rows->sum(fn ($r) => $this->engagement($r)),
            ]);
        });

        return AgentResult::ok([
            'window_days' => $windowDays,
            'post_count' => $rows->count(),
            'pillar_mix' => $pillarMix,
            'format_mix' => $formatMix,
            'platform_mix' => $platformMix,
            'surface_health' => $surfaceHealth,
            'top_count' => count($topPerformers),
            'summary' => $summary,
        ]);
    }

    /**
     * Latest snapshot per scheduled_post in window, joined with the draft
     * + calendar entry so we can attribute by pillar/format.
     *
     * @return \Illuminate\Support\Collection
     */
    private function latestMetricPerPost(Brand $brand, Carbon $startsOn): \Illuminate\Support\Collection
    {
        $latestIds = DB::table('post_metrics')
            ->select(DB::raw('MAX(id) as id'))
            ->where('brand_id', $brand->id)
            ->where('observed_at', '>=', $startsOn)
            ->groupBy('scheduled_post_id')
            ->pluck('id');

        return PostMetric::with(['scheduledPost.draft.calendarEntry'])
            ->whereIn('id', $latestIds)
            ->get();
    }

    private function engagement(PostMetric $m): int
    {
        return (int) (($m->likes ?? 0) + ($m->comments ?? 0) + ($m->shares ?? 0) + ($m->saves ?? 0));
    }

    /**
     * Flatten the metric rows to the plain shape the pure scoring functions
     * take. Keys are positional and stable across all three functions.
     *
     * @return array<int,array{platform:string,impressions:int,engagement:int,pillar:?string,format:?string}>
     */
    private function toPlainPosts(\Illuminate\Support\Collection $rows): array
    {
        return $rows->values()->map(fn (PostMetric $m) => [
            'platform' => (string) ($m->platform ?? 'unknown'),
            'impressions' => (int) ($m->impressions ?? 0),
            'engagement' => $this->engagement($m),
            'pillar' => $m->scheduledPost?->draft?->calendarEntry?->pillar,
            'format' => $m->scheduledPost?->draft?->calendarEntry?->format,
            // Chronology is what makes the collapse check meaningful.
            'published_at' => (string) ($m->scheduledPost?->published_at ?? ''),
        ])->all();
    }

    /**
     * Mid-rank percentile of $x within an ascending-sorted array.
     *
     * Ties share the average rank. A naive "count strictly less than" biases
     * every tied value to the BOTTOM of its group, which at these volumes
     * (most posts share an engagement count of 0 or 1) would drag almost every
     * post toward 0 and flatten the whole signal.
     */
    private static function midrank(array $sortedAsc, int $x): float
    {
        $n = count($sortedAsc);
        if ($n <= 1) {
            return 0.5;
        }
        $lessThan = 0;
        $equal = 0;
        foreach ($sortedAsc as $v) {
            if ($v < $x) {
                $lessThan++;
            } elseif ($v === $x) {
                $equal++;
            }
        }

        return ($lessThan + ($equal - 1) / 2) / ($n - 1);
    }

    /**
     * Per-post value in [0,1] — the mid-rank percentile of the post's
     * engagement and impressions WITHIN ITS OWN PLATFORM, blended at
     * ENGAGEMENT_WEIGHT.
     *
     * Within-platform is the whole point: a TikTok video sitting on the ~110
     * view seed floor and a Facebook post reaching 1 person are both "median
     * for their surface", and must score the same rather than letting TikTok's
     * looser view counter outrank every other network.
     *
     * @param  array<int,array{platform:string,impressions:int,engagement:int}>  $posts
     * @return array<int,float> keyed by the same positional index as $posts
     */
    public static function normalisedPostValues(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $byPlatform = [];
        foreach ($posts as $i => $p) {
            $byPlatform[$p['platform'] ?? 'unknown'][] = $i;
        }

        $values = [];
        foreach ($byPlatform as $indices) {
            $engagements = [];
            $impressions = [];
            foreach ($indices as $i) {
                $engagements[] = (int) $posts[$i]['engagement'];
                $impressions[] = (int) $posts[$i]['impressions'];
            }
            sort($engagements);
            sort($impressions);

            foreach ($indices as $i) {
                $values[$i] = round(
                    self::ENGAGEMENT_WEIGHT * self::midrank($engagements, (int) $posts[$i]['engagement'])
                    + (1 - self::ENGAGEMENT_WEIGHT) * self::midrank($impressions, (int) $posts[$i]['impressions']),
                    6,
                );
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * Normalised distribution over one dimension, using the MEAN per-post
     * value in each bucket — never the sum. Summing would mean "whatever we
     * published most of wins", which is a mirror of past volume rather than a
     * performance signal.
     *
     * Posts whose dimension is null (no calendar entry) are skipped, not
     * bucketed under an empty-string key.
     *
     * @param  array<int,array<string,mixed>>  $posts
     * @param  array<int,float>  $values
     * @return array<string,float>
     */
    public static function meanValueByDimension(array $posts, array $values, string $dim): array
    {
        $bag = [];
        foreach ($posts as $i => $p) {
            $key = $p[$dim] ?? null;
            if ($key === null || $key === '') {
                continue;
            }
            $bag[$key][] = $values[$i] ?? 0.0;
        }
        if ($bag === []) {
            return [];
        }

        $means = [];
        foreach ($bag as $key => $vals) {
            $means[$key] = array_sum($vals) / count($vals);
        }

        return self::normaliseDistribution($means);
    }

    /**
     * Per-platform aggregate stats used by both the viability weighting and
     * the operator-facing surface-health report.
     *
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,array{posts:int,engagement:int,impressions:int,median_impressions:float}>
     */
    private static function platformStats(array $posts): array
    {
        $grouped = [];
        foreach ($posts as $p) {
            $grouped[$p['platform'] ?? 'unknown'][] = $p;
        }

        $stats = [];
        foreach ($grouped as $platform => $rows) {
            // Chronological, so "recent" means recent. Posts without a
            // published_at keep their incoming order.
            usort($rows, fn ($a, $b) => ($a['published_at'] ?? '') <=> ($b['published_at'] ?? ''));

            $imps = array_map(fn ($r) => (int) $r['impressions'], $rows);
            $n = count($imps);

            // Split into "recent tail" vs "everything before it" before sorting,
            // so the comparison is over time rather than over rank.
            $recentCount = max(self::COLLAPSE_MIN_RECENT_POSTS, (int) floor($n * self::COLLAPSE_RECENT_FRACTION));
            $recentMedian = null;
            $priorMedian = null;
            if ($n >= self::DEAD_SURFACE_MIN_POSTS && $recentCount < $n) {
                $recentMedian = self::median(array_slice($imps, -$recentCount));
                $priorMedian = self::median(array_slice($imps, 0, $n - $recentCount));
            }

            $stats[$platform] = [
                'posts' => $n,
                'engagement' => array_sum(array_map(fn ($r) => (int) $r['engagement'], $rows)),
                'impressions' => array_sum($imps),
                'median_impressions' => self::median($imps),
                'recent_median_impressions' => $recentMedian,
                'prior_median_impressions' => $priorMedian,
            ];
        }

        return $stats;
    }

    /** @param array<int,int> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1
            ? (float) $values[intdiv($n, 2)]
            : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /** @param array{posts:int,median_impressions:float} $s */
    private static function isDeadSurface(array $s): bool
    {
        return $s['posts'] >= self::DEAD_SURFACE_MIN_POSTS
            && $s['median_impressions'] <= self::DEAD_SURFACE_MEDIAN_IMPRESSIONS;
    }

    /**
     * Was this surface delivering, and then abruptly stopped?
     *
     * Checked only when the surface is NOT already dead — a platform that never
     * worked is dead, not collapsed, and the two want different operator
     * actions (fix/abandon the page vs check for a platform restriction).
     *
     * @param  array{posts:int,recent_median_impressions:?float,prior_median_impressions:?float}  $s
     */
    private static function isCollapsedSurface(array $s): bool
    {
        $recent = $s['recent_median_impressions'];
        $prior = $s['prior_median_impressions'];

        return $recent !== null
            && $prior !== null
            && $prior >= self::COLLAPSE_PRIOR_MEDIAN_FLOOR
            && $recent <= $prior * self::COLLAPSE_RATIO;
    }

    /**
     * 'dead' | 'collapsed' | 'live'. Order matters: dead wins, because a
     * surface that never delivered cannot have collapsed.
     *
     * @param  array<string,mixed>  $s
     */
    private static function verdictFor(array $s): string
    {
        if (self::isDeadSurface($s)) {
            return 'dead';
        }

        return self::isCollapsedSurface($s) ? 'collapsed' : 'live';
    }

    /**
     * How the next month's cadence should be split across platforms.
     *
     * Unlike per-post scoring this question IS cross-platform, so it can't use
     * within-platform percentiles. It uses the two primitives that carry
     * meaning across networks:
     *
     *   - engagement per post — an interaction is an interaction anywhere.
     *     Laplace-smoothed so a zero-engagement month can't permanently lock a
     *     surface out.
     *   - median impressions — imperfect (a LinkedIn feed impression is not a
     *     TikTok view) and therefore weighted lower, but it is the only reach
     *     signal available and it is what exposes a surface that simply is not
     *     delivering.
     *
     * Surfaces judged dead collapse to a token exploration weight instead of
     * their proportional share.
     *
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,float>
     */
    public static function platformViability(array $posts): array
    {
        $stats = self::platformStats($posts);
        if ($stats === []) {
            return [];
        }

        $engagementPerPost = [];
        $medianImpressions = [];
        foreach ($stats as $platform => $s) {
            $engagementPerPost[$platform] = ($s['engagement'] + 1) / ($s['posts'] + 1);
            $medianImpressions[$platform] = $s['median_impressions'];
        }
        $engagementShare = self::normaliseDistribution($engagementPerPost);
        $reachShare = self::normaliseDistribution($medianImpressions);

        $raw = [];
        foreach ($stats as $platform => $s) {
            // Dead and collapsed both collapse to the token weight: one never
            // delivered, the other has stopped, and neither should attract more
            // of next month's cadence than it can carry.
            if (self::verdictFor($s) !== 'live') {
                $raw[$platform] = self::DEAD_SURFACE_WEIGHT;

                continue;
            }
            $raw[$platform] = max(
                self::MIN_PLATFORM_WEIGHT,
                self::ENGAGEMENT_WEIGHT * ($engagementShare[$platform] ?? 0.0)
                + (1 - self::ENGAGEMENT_WEIGHT) * ($reachShare[$platform] ?? 0.0),
            );
        }

        return self::normaliseDistribution($raw);
    }

    /**
     * Operator-facing verdict per surface. The weighting above quietly
     * de-prioritises a dead platform; this names it, with the evidence, so a
     * human can make the irreversible call (pause the surface, fix the page,
     * appeal a restriction) rather than the system silently deciding.
     *
     * @param  array<int,array<string,mixed>>  $posts
     * @return array<string,array<string,mixed>>
     */
    public static function surfaceHealth(array $posts): array
    {
        $out = [];
        foreach (self::platformStats($posts) as $platform => $s) {
            $out[$platform] = [
                'verdict' => self::verdictFor($s),
                'posts' => $s['posts'],
                'impressions' => $s['impressions'],
                'engagement' => $s['engagement'],
                'median_impressions' => $s['median_impressions'],
                'recent_median_impressions' => $s['recent_median_impressions'],
                'prior_median_impressions' => $s['prior_median_impressions'],
            ];
        }

        return $out;
    }

    /**
     * Scale a bag of non-negative weights so it sums to 1.
     *
     * @param  array<string,float>  $bag
     * @return array<string,float>
     */
    private static function normaliseDistribution(array $bag): array
    {
        $total = array_sum($bag);
        if ($total <= 0) {
            return [];
        }
        $out = [];
        foreach ($bag as $k => $v) {
            $out[$k] = round($v / $total, 4);
        }

        return $out;
    }

    /**
     * Blend the data-driven distribution with the default floor so we
     * keep variety even when one pillar is winning hard. Default weights
     * 0.5 each — operator can override via config in v1.1.
     *
     * @param array<string,float> $observed
     * @param array<string,float> $defaults
     * @return array<string,float>
     */
    private function blendMix(array $observed, array $defaults): array
    {
        $w = self::RECOMMENDATION_WEIGHT;
        $blended = [];
        foreach ($defaults as $key => $defaultPct) {
            $observedPct = $observed[$key] ?? 0.0;
            $blended[$key] = round($observedPct * $w + $defaultPct * (1 - $w), 4);
        }
        // Ensure new dimensions in `observed` (shouldn't happen for fixed
        // schemas but defensive) carry through at half-weight.
        foreach ($observed as $key => $val) {
            if (! array_key_exists($key, $blended)) {
                $blended[$key] = round($val * 0.5, 4);
            }
        }
        // Normalise to sum=1.
        $sum = array_sum($blended);
        if ($sum > 0) {
            foreach ($blended as $k => $v) {
                $blended[$k] = round($v / $sum, 4);
            }
        }
        return $blended;
    }

    /** @return array<int, array<string,mixed>> */
    /**
     * The exemplars handed to the operator (and read as "what worked").
     *
     * Ranked by the normalised within-platform value, so a post that reached
     * many feeds but moved nobody can no longer take the top slot — under the
     * old impressions-dominated score, the #1 exemplar for three straight weeks
     * was a LinkedIn carousel with 951 impressions and zero engagement.
     *
     * @param  array<int,float>  $values  positional, aligned with $rows->values()
     * @return array<int, array<string,mixed>>
     */
    private function topN(\Illuminate\Support\Collection $rows, array $values, int $n): array
    {
        return $rows
            ->values()
            ->map(fn (PostMetric $m, int $i) => ['metric' => $m, 'value' => $values[$i] ?? 0.0])
            // Tie-break on absolute engagement then reach: percentiles tie often
            // at these volumes, and an arbitrary pick would make the exemplar
            // list jitter between runs on identical data.
            ->sortByDesc(fn (array $r) => sprintf(
                '%08.6f|%08d|%010d',
                $r['value'],
                $this->engagement($r['metric']),
                (int) ($r['metric']->impressions ?? 0),
            ))
            ->take($n)
            ->map(function (array $r) {
                $m = $r['metric'];
                $impressions = (int) ($m->impressions ?? 0);
                $engagement = $this->engagement($m);

                return [
                    'scheduled_post_id' => $m->scheduled_post_id,
                    'platform' => $m->platform,
                    'pillar' => $m->scheduledPost?->draft?->calendarEntry?->pillar,
                    'format' => $m->scheduledPost?->draft?->calendarEntry?->format,
                    'value' => round($r['value'], 4),
                    'impressions' => $m->impressions,
                    'engagement' => $engagement,
                    'engagement_rate' => $impressions > 0 ? round($engagement / $impressions, 4) : null,
                    'preview' => substr((string) ($m->scheduledPost?->draft?->body ?? '—'), 0, 100),
                    'url' => $m->scheduledPost?->platform_post_url,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,float> $pillarMix
     * @param array<string,float> $formatMix
     * @param array<string,float> $platformMix
     * @param array<string,array<string,mixed>> $surfaceHealth
     */
    private function buildSummary(
        \Illuminate\Support\Collection $rows,
        array $pillarMix,
        array $formatMix,
        array $platformMix,
        array $surfaceHealth = [],
    ): string {
        arsort($pillarMix);
        arsort($formatMix);
        arsort($platformMix);

        $topPillar = array_key_first($pillarMix);   // null when the dimension is empty
        $topFormat = array_key_first($formatMix);
        $topPlatform = array_key_first($platformMix);

        $totalImpr = (int) $rows->sum(fn ($r) => (int) ($r->impressions ?? 0));
        $totalEng = (int) $rows->sum(fn ($r) => $this->engagement($r));

        // Build each clause independently. When a dimension has no labelled posts
        // (every post had a null pillar/format/platform), array_key_first returns
        // null — substitute a neutral phrase instead of coercing null to an empty
        // token, which used to garble the sentence ("; format is winning ...").
        $reach = $topPlatform !== null
            ? sprintf('%s reached the most', ucfirst((string) $topPlatform))
            : 'No single platform led';

        $pillarClause = $topPillar !== null
            ? sprintf('%s pillar is your best-performing voice', ucfirst(str_replace('_', ' ', (string) $topPillar)))
            : 'No clear pillar leader yet';

        $formatClause = $topFormat !== null
            ? sprintf('%s format is winning the algorithm', str_replace('_', ' ', (string) $topFormat))
            : 'no clear format leader yet';

        // Name any surface that is not delivering, with the evidence. The
        // weighting already de-prioritises it; this is what tells a human to
        // make the call the system deliberately will not make on its own.
        $deadClause = '';

        $dead = array_filter($surfaceHealth, fn ($h) => ($h['verdict'] ?? null) === 'dead');
        if ($dead !== []) {
            $parts = [];
            foreach ($dead as $platform => $h) {
                $parts[] = sprintf(
                    '%s (%d posts, %s impressions, %s engagement)',
                    ucfirst((string) $platform),
                    $h['posts'],
                    number_format((int) $h['impressions']),
                    number_format((int) $h['engagement']),
                );
            }
            $deadClause .= sprintf(
                ' Not delivering: %s — cadence reduced to a token share; review the account before spending more on it.',
                implode('; ', $parts),
            );
        }

        // Collapsed reads differently to the operator: the surface WAS working,
        // so the action is to check for a platform restriction, not to fix or
        // abandon the page.
        $collapsed = array_filter($surfaceHealth, fn ($h) => ($h['verdict'] ?? null) === 'collapsed');
        if ($collapsed !== []) {
            $parts = [];
            foreach ($collapsed as $platform => $h) {
                $parts[] = sprintf(
                    '%s (was ~%s impressions per post, now ~%s)',
                    ucfirst((string) $platform),
                    number_format((float) $h['prior_median_impressions'], 0),
                    number_format((float) $h['recent_median_impressions'], 0),
                );
            }
            $deadClause .= sprintf(
                ' Stopped delivering: %s — recent posts collapsed against this account\'s own history;'
                .' check the platform for a restriction on the account before publishing more there.',
                implode('; ', $parts),
            );
        }

        return sprintf(
            'Across %d posts: %s with %s impressions and %s engagement. %s; %s. Strategist will weight the next calendar toward this mix.%s',
            $rows->count(),
            $reach,
            number_format($totalImpr),
            number_format($totalEng),
            $pillarClause,
            $formatClause,
            $deadClause,
        );
    }
}
