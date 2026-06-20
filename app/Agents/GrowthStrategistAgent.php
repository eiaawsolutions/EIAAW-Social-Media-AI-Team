<?php

namespace App\Agents;

use App\Agents\Prompts\GrowthStrategistPrompt;
use App\Models\Brand;
use App\Models\BrandGrowthGoal;
use App\Models\GrowthStrategyBrief;
use App\Models\PostMetric;
use App\Services\Metricool\AccountGrowthService;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The 4th weekly-synthesis sibling (alongside CompetitorStrategist + MarketIntel).
 * Turns a brand's OWN real performance into growth guidance: best posting times,
 * winning hook patterns, CTA/conversion lift, reach/platform focus, follower
 * velocity, recommended objective mix, and per-objective hook/CTA guidance.
 *
 * Disjoint from OptimizerAgent: Optimizer learns the pillar/format/platform MIX;
 * this learns TIME / HOOK / CTA / CONVERSION / REACH / VELOCITY / OBJECTIVE. It
 * never writes StrategistRecommendation or recomputes the mix; it only READS the
 * same post_metrics with a different lens.
 *
 * Split (mirrors CompetitorStrategistAgent): PHP computes every number from real
 * data; the LLM only narrates + maps objectives → proven hooks/CTAs. Thin data →
 * soft-skip (never fabricate). The brief has FOUR homes of influence: Strategist
 * (plan), Writer (per-objective copy), the auto-scheduler (best times), and the
 * dashboard card.
 */
class GrowthStrategistAgent extends BaseAgent
{
    /** No readiness gating — runs in the weekly intel beat. */
    protected array $requiredStages = [];

    /** Need enough published-with-metrics posts before slicing by hook/time/CTA. */
    private const MIN_POSTS_FOR_GROWTH = 6;

    /** A hook/time bucket needs at least this many posts to be trustworthy. */
    private const MIN_SAMPLE_PER_BUCKET = 2;

    private const WINDOW_DAYS = 30;

    public function __construct(
        LlmGateway $llm,
        private readonly AccountGrowthService $growth,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'growth_strategist'; }
    public function promptVersion(): string { return GrowthStrategistPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        if (! (bool) config('services.growth_strategy.enabled', true)) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'growth strategy disabled']);
        }

        $config = $brand->growth_strategy_config ?? [];
        if (array_key_exists('enabled', $config) && ! $config['enabled']) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'disabled per brand']);
        }

        $minPosts = (int) config('services.growth_strategy.min_posts', self::MIN_POSTS_FOR_GROWTH);

        $endsOn = Carbon::now();
        $startsOn = $endsOn->copy()->subDays(self::WINDOW_DAYS);

        $rows = $this->latestMetricPerPost($brand, $startsOn);

        if ($rows->count() < $minPosts) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => sprintf('only %d post(s) with metrics in window (need ≥%d)', $rows->count(), $minPosts),
            ]);
        }

        // ── Compute every signal deterministically in PHP ───────────────
        $hookPerformance = self::computeHookPerformance($rows, self::MIN_SAMPLE_PER_BUCKET);
        $bestPostingTimes = self::computeBestPostingTimes($rows, self::MIN_SAMPLE_PER_BUCKET);
        $ctaLift = self::computeCtaLift($rows);
        $platformFocus = self::computePlatformFocus($rows);
        $objectiveMix = self::computeObjectiveMix($rows);
        $followerVelocity = self::classifyFollowerVelocity($this->safeForBrand($brand));
        $goalProgress = $this->computeGoalProgress($brand, $followerVelocity);

        // ── LLM narrates the computed facts + maps objective → hooks/CTAs ──
        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: GrowthStrategistPrompt::system(),
            userMessage: $this->buildUserMessage(
                $brand, $hookPerformance, $bestPostingTimes, $ctaLift,
                $platformFocus, $objectiveMix, $followerVelocity, $goalProgress,
            ),
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 3000,
            jsonSchema: GrowthStrategistPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! is_array($payload)) {
            return AgentResult::fail('Growth synthesis came back empty. Prior brief retained.');
        }

        // Drop any hook the model recommended outside the allowed enum set
        // (hallucination-drop, mirrors CompetitorStrategist::filterThemes).
        $objectiveGuidance = self::filterObjectiveGuidance($payload['objective_guidance'] ?? []);

        DB::transaction(function () use (
            $brand, $startsOn, $endsOn, $bestPostingTimes, $platformFocus, $hookPerformance,
            $ctaLift, $followerVelocity, $objectiveMix, $goalProgress, $objectiveGuidance,
            $payload, $rows, $result
        ): void {
            GrowthStrategyBrief::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            GrowthStrategyBrief::create([
                'brand_id' => $brand->id,
                'workspace_id' => $brand->workspace_id,
                'is_current' => true,
                'window_starts_on' => $startsOn->toDateString(),
                'window_ends_on' => $endsOn->toDateString(),
                'best_posting_times' => $bestPostingTimes,
                'platform_focus' => $platformFocus,
                'hook_performance' => $hookPerformance,
                'cta_lift' => $ctaLift,
                'follower_velocity' => $followerVelocity,
                'recommended_objective_mix' => $objectiveMix,
                'goal_progress' => $goalProgress,
                'objective_guidance' => $objectiveGuidance,
                'rationale' => isset($payload['rationale']) ? (string) $payload['rationale'] : null,
                'summary' => isset($payload['summary']) ? (string) $payload['summary'] : null,
                'post_count_in_window' => $rows->count(),
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => (float) ($result->costUsd ?? 0),
            ]);

            // Stamp last_refreshed_at on the brand config (audit, like CompetitorIntelAgent).
            $cfg = array_merge((array) ($brand->growth_strategy_config ?? []), [
                'last_refreshed_at' => now()->toIso8601String(),
            ]);
            $brand->forceFill(['growth_strategy_config' => $cfg])->save();
        });

        return AgentResult::ok([
            'brand_id' => $brand->id,
            'post_count' => $rows->count(),
            'hook_count' => count($hookPerformance),
            'cta_signal' => ($ctaLift['has_signal'] ?? false) ? 'yes' : 'no',
            'platforms' => count($platformFocus),
            'goals' => count($goalProgress),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    // ───────────────────────── PHP signal computers (pure-static) ─────────────────────────

    /** OptimizerAgent's score formula — kept identical so dashboards/agents agree. */
    public static function score(PostMetric $m): float
    {
        return (int) ($m->impressions ?? 0)
            + 5 * (int) ($m->likes ?? 0)
            + 10 * (int) ($m->comments ?? 0)
            + 20 * (int) ($m->shares ?? 0)
            + 30 * (int) ($m->saves ?? 0);
    }

    public static function engagement(PostMetric $m): int
    {
        return (int) (($m->likes ?? 0) + ($m->comments ?? 0) + ($m->shares ?? 0) + ($m->saves ?? 0));
    }

    /**
     * Hook-pattern win-rates. win_rate = fraction of a hook's posts scoring above
     * the median post score across the window. Drops hooks below the sample floor
     * so a single lucky post can't crown a pattern.
     *
     * @param  Collection<int,PostMetric>  $rows
     * @return array<int,array{hook_pattern:string,avg_engagement:float,win_rate:float,sample_n:int}>
     */
    public static function computeHookPerformance(Collection $rows, int $minSample = 2): array
    {
        $scores = $rows->map(fn (PostMetric $m) => self::score($m))->sort()->values();
        $median = self::median($scores->all());

        $byHook = [];
        foreach ($rows as $m) {
            $hook = self::hookOf($m);
            if ($hook === null) {
                continue;
            }
            $byHook[$hook] ??= ['engagement_sum' => 0, 'wins' => 0, 'n' => 0];
            $byHook[$hook]['engagement_sum'] += self::engagement($m);
            $byHook[$hook]['wins'] += self::score($m) > $median ? 1 : 0;
            $byHook[$hook]['n']++;
        }

        $out = [];
        foreach ($byHook as $hook => $agg) {
            if ($agg['n'] < $minSample) {
                continue;
            }
            $out[] = [
                'hook_pattern' => $hook,
                'avg_engagement' => round($agg['engagement_sum'] / $agg['n'], 1),
                'win_rate' => round($agg['wins'] / $agg['n'], 2),
                'sample_n' => $agg['n'],
            ];
        }

        usort($out, fn ($a, $b) => $b['avg_engagement'] <=> $a['avg_engagement']);

        return $out;
    }

    /**
     * Best posting time per platform from REAL published_at × score. Returns the
     * top 1–2 (day_of_week, hour) buckets per platform above the sample floor.
     *
     * @param  Collection<int,PostMetric>  $rows
     * @return array<string,array<int,array{day_of_week:int,hour:int,avg_score:float,sample_n:int}>>
     */
    public static function computeBestPostingTimes(Collection $rows, int $minSample = 2): array
    {
        $buckets = [];
        foreach ($rows as $m) {
            $publishedAt = $m->scheduledPost?->published_at;
            $platform = $m->platform;
            if (! $publishedAt || ! $platform) {
                continue;
            }
            $dow = (int) $publishedAt->dayOfWeek; // 0=Sun..6=Sat
            $hour = (int) $publishedAt->hour;
            $key = "{$platform}|{$dow}|{$hour}";
            $buckets[$key] ??= ['platform' => $platform, 'dow' => $dow, 'hour' => $hour, 'score_sum' => 0.0, 'n' => 0];
            $buckets[$key]['score_sum'] += self::score($m);
            $buckets[$key]['n']++;
        }

        $byPlatform = [];
        foreach ($buckets as $b) {
            if ($b['n'] < $minSample) {
                continue;
            }
            $byPlatform[$b['platform']][] = [
                'day_of_week' => $b['dow'],
                'hour' => $b['hour'],
                'avg_score' => round($b['score_sum'] / $b['n'], 1),
                'sample_n' => $b['n'],
            ];
        }

        // Keep the top 2 buckets per platform.
        foreach ($byPlatform as $platform => $list) {
            usort($list, fn ($a, $b) => $b['avg_score'] <=> $a['avg_score']);
            $byPlatform[$platform] = array_slice($list, 0, 2);
        }

        return $byPlatform;
    }

    /**
     * CTA-presence conversion lift. Averages url_clicks + profile_visits over
     * READINGS ONLY (NULL excluded from both numerator and denominator — never
     * coerced to 0). has_signal=false when neither partition has a real
     * conversion reading (most platforms don't expose these).
     *
     * @param  Collection<int,PostMetric>  $rows
     * @return array<string,mixed>
     */
    public static function computeCtaLift(Collection $rows): array
    {
        $with = ['clicks' => [], 'visits' => []];
        $without = ['clicks' => [], 'visits' => []];

        foreach ($rows as $m) {
            $hasCta = self::ctaOf($m) !== '';
            if ($m->url_clicks !== null) {
                if ($hasCta) {
                    $with['clicks'][] = (int) $m->url_clicks;
                } else {
                    $without['clicks'][] = (int) $m->url_clicks;
                }
            }
            if ($m->profile_visits !== null) {
                if ($hasCta) {
                    $with['visits'][] = (int) $m->profile_visits;
                } else {
                    $without['visits'][] = (int) $m->profile_visits;
                }
            }
        }

        $withClicks = self::avgOrNull($with['clicks']);
        $withoutClicks = self::avgOrNull($without['clicks']);
        $withVisits = self::avgOrNull($with['visits']);
        $withoutVisits = self::avgOrNull($without['visits']);

        // A signal exists only if BOTH partitions have a real click reading.
        $hasSignal = $withClicks !== null && $withoutClicks !== null;
        $liftPct = null;
        if ($hasSignal && $withoutClicks > 0) {
            $liftPct = round(($withClicks - $withoutClicks) / $withoutClicks * 100, 1);
        }

        return [
            'with_cta' => ['avg_url_clicks' => $withClicks, 'avg_profile_visits' => $withVisits, 'n' => count($with['clicks'])],
            'without_cta' => ['avg_url_clicks' => $withoutClicks, 'avg_profile_visits' => $withoutVisits, 'n' => count($without['clicks'])],
            'lift_pct' => $liftPct,
            'has_signal' => $hasSignal,
        ];
    }

    /**
     * Reach-delivery share per platform (distinct from Optimizer's score-weighted
     * platform_mix). reach with impressions fallback; readings only.
     *
     * @param  Collection<int,PostMetric>  $rows
     * @return array<string,array{reach_share_pct:float,impressions_share_pct:float,sample_n:int}>
     */
    public static function computePlatformFocus(Collection $rows): array
    {
        $reach = [];
        $impr = [];
        $n = [];
        foreach ($rows as $m) {
            $platform = $m->platform;
            if (! $platform) {
                continue;
            }
            $r = $m->reach ?? $m->impressions; // reach with impressions fallback
            if ($r !== null) {
                $reach[$platform] = ($reach[$platform] ?? 0) + (int) $r;
                $n[$platform] = ($n[$platform] ?? 0) + 1;
            }
            if ($m->impressions !== null) {
                $impr[$platform] = ($impr[$platform] ?? 0) + (int) $m->impressions;
            }
        }

        $reachTotal = array_sum($reach);
        $imprTotal = array_sum($impr);
        if ($reachTotal <= 0 && $imprTotal <= 0) {
            return [];
        }

        $out = [];
        foreach (array_keys($n) as $platform) {
            $out[$platform] = [
                'reach_share_pct' => $reachTotal > 0 ? round(($reach[$platform] ?? 0) / $reachTotal * 100, 1) : 0.0,
                'impressions_share_pct' => $imprTotal > 0 ? round(($impr[$platform] ?? 0) / $imprTotal * 100, 1) : 0.0,
                'sample_n' => $n[$platform],
            ];
        }
        uasort($out, fn ($a, $b) => $b['reach_share_pct'] <=> $a['reach_share_pct']);

        return $out;
    }

    /**
     * Recommended objective distribution — biased toward objectives that drove
     * real clicks/engagement for THIS brand, blended 50/50 with an even default
     * floor so a thin objective doesn't drop to zero (OptimizerAgent::blendMix idiom).
     *
     * @param  Collection<int,PostMetric>  $rows
     * @return array<string,float>
     */
    public static function computeObjectiveMix(Collection $rows): array
    {
        $objectives = GrowthStrategistPrompt::OBJECTIVES;
        $bag = array_fill_keys($objectives, 0.0);

        foreach ($rows as $m) {
            $obj = self::objectiveOf($m);
            if ($obj === null || ! array_key_exists($obj, $bag)) {
                continue;
            }
            // Reward conversions (clicks + visits) heavily, engagement lightly.
            $clicks = (int) ($m->url_clicks ?? 0) + (int) ($m->profile_visits ?? 0);
            $bag[$obj] += $clicks * 10 + self::engagement($m);
        }

        $total = array_sum($bag);
        $observed = [];
        if ($total > 0) {
            foreach ($bag as $k => $v) {
                $observed[$k] = $v / $total;
            }
        }

        // Blend with an even default floor (0.5 weight), then normalise.
        $evenDefault = 1.0 / count($objectives);
        $blended = [];
        foreach ($objectives as $obj) {
            $blended[$obj] = ($observed[$obj] ?? 0.0) * 0.5 + $evenDefault * 0.5;
        }
        $sum = array_sum($blended);
        foreach ($blended as $k => $v) {
            $blended[$k] = round($v / $sum, 4);
        }

        return $blended;
    }

    /**
     * Classify per-network follower momentum from an AccountGrowthService::forBrand
     * payload. Includes ONLY networks whose followers row has status==='ok' (the
     * service already enforces the Truthfulness Contract: not_available/no_data/
     * error networks are excluded here).
     *
     * @param  array<string,mixed>  $forBrand  output of AccountGrowthService::forBrand()
     * @return array<string,array{net_new:int,direction:string,latest:?int,label:string}>
     */
    public static function classifyFollowerVelocity(array $forBrand): array
    {
        $followers = $forBrand['followers'] ?? null;
        $networks = is_array($followers['networks'] ?? null) ? $followers['networks'] : [];

        $out = [];
        foreach ($networks as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'ok') {
                continue;
            }
            $netNew = (int) ($row['change'] ?? 0);
            $latest = $row['headline'] !== null ? (int) $row['headline'] : null;

            // Direction relative to the base: a +2%+ window gain is accelerating,
            // a >1% loss is declining, otherwise flat. Guards a zero base.
            $direction = 'flat';
            if ($latest !== null && $latest > 0) {
                $pct = $netNew / max(1, $latest - $netNew) * 100;
                if ($pct >= 2.0) {
                    $direction = 'accelerating';
                } elseif ($pct <= -1.0) {
                    $direction = 'declining';
                }
            } elseif ($netNew > 0) {
                $direction = 'accelerating';
            } elseif ($netNew < 0) {
                $direction = 'declining';
            }

            $out[(string) ($row['network'] ?? '')] = [
                'net_new' => $netNew,
                'direction' => $direction,
                'latest' => $latest,
                'label' => (string) ($row['label'] ?? $row['network'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Keep only objective_guidance entries with valid objective keys, and within
     * each drop any hook outside the allowed enum set (hallucination-drop).
     *
     * @param  array<string,mixed>  $guidance
     * @return array<string,array{hook_patterns:array<int,string>,cta_styles:array<int,string>}>
     */
    public static function filterObjectiveGuidance(array $guidance): array
    {
        $allowedHooks = array_flip(GrowthStrategistPrompt::HOOK_PATTERNS);
        $allowedObjectives = array_flip(GrowthStrategistPrompt::OBJECTIVES);

        $out = [];
        foreach ($guidance as $objective => $g) {
            if (! isset($allowedObjectives[$objective]) || ! is_array($g)) {
                continue;
            }
            $hooks = [];
            foreach ((array) ($g['hook_patterns'] ?? []) as $h) {
                $h = (string) $h;
                if (isset($allowedHooks[$h])) {
                    $hooks[$h] = true;
                }
            }
            $ctas = [];
            foreach ((array) ($g['cta_styles'] ?? []) as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $ctas[$c] = true;
                }
            }
            if ($hooks === [] && $ctas === []) {
                continue;
            }
            $out[$objective] = [
                'hook_patterns' => array_keys($hooks),
                'cta_styles' => array_keys($ctas),
            ];
        }

        return $out;
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return string|null hook_pattern from the post's draft platform_payload */
    private static function hookOf(PostMetric $m): ?string
    {
        $hook = $m->scheduledPost?->draft?->platform_payload['hook_pattern'] ?? null;
        $hook = is_string($hook) ? trim($hook) : '';

        return $hook !== '' ? $hook : null;
    }

    /** @return string cta from the post's draft platform_payload ('' when absent) */
    private static function ctaOf(PostMetric $m): string
    {
        $cta = $m->scheduledPost?->draft?->platform_payload['cta'] ?? null;

        return is_string($cta) ? trim($cta) : '';
    }

    /** @return string|null calendar entry objective */
    private static function objectiveOf(PostMetric $m): ?string
    {
        $obj = $m->scheduledPost?->draft?->calendarEntry?->objective ?? null;
        $obj = is_string($obj) ? trim($obj) : '';

        return $obj !== '' ? $obj : null;
    }

    /** @param array<int,int> $values */
    private static function avgOrNull(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /** @param array<int,float> $sorted-or-unsorted */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
    }

    /**
     * Latest metric snapshot per scheduled_post in the window, eager-loaded with
     * the draft + calendar entry so hook/cta/objective resolve without N+1.
     * Reuses OptimizerAgent::latestMetricPerPost shape.
     *
     * @return Collection<int,PostMetric>
     */
    private function latestMetricPerPost(Brand $brand, Carbon $startsOn): Collection
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

    /** AccountGrowthService can throw on a Metricool outage — degrade to empty. */
    private function safeForBrand(Brand $brand): array
    {
        try {
            return $this->growth->forBrand($brand, self::WINDOW_DAYS);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Compute progress for each active goal from REAL current values. followers
     * come from the just-fetched velocity map; metrics we don't have a live
     * reading for are returned with current=null (progress '—').
     *
     * @param  array<string,mixed>  $followerVelocity
     * @return array<int,array<string,mixed>>
     */
    private function computeGoalProgress(Brand $brand, array $followerVelocity): array
    {
        $goals = BrandGrowthGoal::active()->where('brand_id', $brand->id)->get();
        if ($goals->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($goals as $goal) {
            $current = null;
            if ($goal->target_metric === 'followers' && $goal->platform) {
                $net = $followerVelocity[$goal->platform]['latest'] ?? null;
                $current = $net !== null ? (int) $net : null;
            }
            // reach / engagement_rate / link_clicks / profile_visits live in
            // post_metrics aggregates; left null here (current reading optional),
            // surfaced honestly as "—" until a first-party reading exists.

            $progressPct = BrandGrowthGoal::progressPct($goal->baseline_value, $goal->target_value, $current);

            // Pace = progress vs. linear time elapsed in the goal window. A goal
            // at 30% with 60% of its window gone is 'lagging'. Both the verdict
            // and the expected number are real-reading-only (null progress →
            // null pace) so the Strategist applies pressure only on evidence.
            $start = $goal->window_starts_on ? $goal->window_starts_on->copy()->startOfDay() : null;
            $end = $goal->window_ends_on ? $goal->window_ends_on->copy()->endOfDay() : null;
            $paceStatus = ($start && $end)
                ? BrandGrowthGoal::paceStatus($progressPct, $start, $end, now())
                : null;
            $expectedPct = ($start && $end)
                ? BrandGrowthGoal::expectedPct($start, $end, now())
                : null;

            $out[] = [
                'target_metric' => $goal->target_metric,
                'platform' => $goal->platform,
                'target_value' => $goal->target_value,
                'baseline_value' => $goal->baseline_value,
                'current_value' => $current,
                'progress_pct' => $progressPct,
                'expected_pct' => $expectedPct,
                'pace_status' => $paceStatus,
                'window_ends_on' => $goal->window_ends_on?->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $hookPerformance
     * @param  array<string,mixed>             $bestPostingTimes
     * @param  array<string,mixed>             $ctaLift
     * @param  array<string,mixed>             $platformFocus
     * @param  array<string,float>             $objectiveMix
     * @param  array<string,mixed>             $followerVelocity
     * @param  array<int,array<string,mixed>>  $goalProgress
     */
    private function buildUserMessage(
        Brand $brand,
        array $hookPerformance,
        array $bestPostingTimes,
        array $ctaLift,
        array $platformFocus,
        array $objectiveMix,
        array $followerVelocity,
        array $goalProgress,
    ): string {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $hookLines = collect($hookPerformance)
            ->map(fn ($h) => "- {$h['hook_pattern']}: avg engagement {$h['avg_engagement']}, win rate ".round($h['win_rate'] * 100)."% (n={$h['sample_n']})")
            ->implode("\n") ?: '(not enough data per hook)';

        $timeLines = collect($bestPostingTimes)
            ->map(function ($buckets, $platform) use ($days) {
                $slots = collect($buckets)->map(fn ($b) => "{$days[$b['day_of_week']]} {$b['hour']}:00 (avg score {$b['avg_score']}, n={$b['sample_n']})")->implode('; ');
                return "- {$platform}: {$slots}";
            })->implode("\n") ?: '(not enough timing data)';

        $platformLines = collect($platformFocus)
            ->map(fn ($p, $platform) => "- {$platform}: {$p['reach_share_pct']}% of reach (n={$p['sample_n']})")
            ->implode("\n") ?: '(no reach data)';

        $ctaLine = ($ctaLift['has_signal'] ?? false)
            ? "Posts WITH a CTA average {$ctaLift['with_cta']['avg_url_clicks']} link clicks vs {$ctaLift['without_cta']['avg_url_clicks']} without — lift {$ctaLift['lift_pct']}%."
            : '(no CTA-lift signal — the connected platforms do not expose link-click data)';

        $velocityLines = collect($followerVelocity)
            ->map(fn ($v, $net) => "- {$v['label']}: {$v['direction']} ({$v['net_new']} net new over 30d, now {$v['latest']})")
            ->implode("\n") ?: '(no follower data available)';

        $objectiveLine = collect($objectiveMix)
            ->map(fn ($pct, $obj) => "{$obj} ".round($pct * 100)."%")
            ->implode(', ');

        $goalLines = collect($goalProgress)
            ->map(function ($g) {
                $scope = $g['platform'] ? " ({$g['platform']})" : '';
                $prog = $g['progress_pct'] !== null ? "{$g['progress_pct']}% to target" : 'progress not yet measurable';
                return "- Target {$g['target_metric']}{$scope}: {$g['target_value']} by {$g['window_ends_on']} — {$prog}";
            })->implode("\n");
        $goalBlock = $goalLines !== '' ? "\n# Active growth goals (bias your guidance toward these)\n{$goalLines}\n" : '';

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}

# Computed signals (FACTS — restate, never alter; output no numbers of your own)

## Winning hook patterns (by avg engagement)
{$hookLines}

## Best posting times (from real publish times × engagement)
{$timeLines}

## Platform reach focus
{$platformLines}

## CTA / conversion lift
{$ctaLine}

## Follower momentum
{$velocityLines}

## Objective mix that drove results (recommended distribution)
{$objectiveLine}
{$goalBlock}
# Allowed hook patterns (recommend ONLY from these)
curiosity_gap, problem_agitation, contrarian, relatable, authority_insight, shock_statistic, transformation, story

Produce objective_guidance (per objective: hook_patterns from the allowed list + cta_styles), a rationale tying recommendations to the signals above, and a summary. Output only the JSON.
MSG;
    }
}
