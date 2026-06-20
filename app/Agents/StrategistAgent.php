<?php

namespace App\Agents;

use App\Agents\Prompts\StrategistPrompt;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\CompetitorAd;
use App\Models\CompetitorStrategyBrief;
use App\Models\ContentCalendar;
use App\Models\GrowthStrategyBrief;
use App\Models\MarketTrendBrief;
use App\Services\Compliance\LegalRulesProvider;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a month of content. Stage 6 of the wizard.
 *
 * Inputs: brand_style + active platform connections + pillar/format mix targets.
 * Outputs: a ContentCalendar with N CalendarEntry rows.
 *
 * Default mix is sensible if the user hasn't specified one. They can override
 * via $input['pillar_mix'] / $input['format_mix'].
 */
class StrategistAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style', 'platform_connected'];

    private const DEFAULT_PILLAR_MIX = [
        'educational' => 0.30,
        'community' => 0.25,
        'promotional' => 0.15,
        'behind_the_scenes' => 0.15,
        'thought_leadership' => 0.15,
    ];

    private const DEFAULT_FORMAT_MIX = [
        'single_image' => 0.30,
        'carousel' => 0.30,
        'reel' => 0.20,
        'text_only' => 0.15,
        'video' => 0.05,
    ];

    public function role(): string { return 'strategist'; }
    public function promptVersion(): string { return StrategistPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice has not been synthesised yet. Run Onboarding first.');
        }

        $activePlatforms = $brand->platformConnections()
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        if (empty($activePlatforms)) {
            return AgentResult::fail('No active platform connections. Connect at least one platform first.');
        }

        // Optimizer recommendation (if any) overrides the static defaults
        // unless caller explicitly passed mixes. This is the learning loop:
        // every Monday 02:00 UTC the OptimizerAgent rewrites the
        // recommendation based on last 30 days of post_metrics; this call
        // reads the freshest current=true row and weights the next month's
        // calendar accordingly.
        $reco = \App\Models\StrategistRecommendation::where('brand_id', $brand->id)
            ->where('is_current', true)
            ->latest()
            ->first();
        $pillarMix = $input['pillar_mix']
            ?? ($reco?->pillar_mix && ! empty($reco->pillar_mix) ? $reco->pillar_mix : self::DEFAULT_PILLAR_MIX);
        $formatMix = $input['format_mix']
            ?? ($reco?->format_mix && ! empty($reco->format_mix) ? $reco->format_mix : self::DEFAULT_FORMAT_MIX);
        $startsOn = isset($input['period_starts_on'])
            ? Carbon::parse($input['period_starts_on'])
            : Carbon::now($brand->timezone)->startOfMonth();
        $endsOn = $startsOn->copy()->endOfMonth();

        $competitorBlock = $this->renderCompetitorSignals($brand);

        $userMessage = $this->buildUserMessage($brand, $brandStyle->content_md, $activePlatforms, $pillarMix, $formatMix, $startsOn, $endsOn, $competitorBlock);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: StrategistPrompt::system(),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 8000,
            jsonSchema: StrategistPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! $payload || empty($payload['entries'])) {
            return AgentResult::fail('Calendar synthesis came back empty. Try again.');
        }

        // Range enforcement lives here, not in the JSON schema, because
        // Anthropic's structured-output validator only allows minItems of
        // 0 or 1 (and rejects bounded maxItems on some models). The system
        // prompt asks for 30 entries; we accept anything ≥10 as a usable
        // calendar and reject thinner responses as a failed plan.
        $entryCount = count($payload['entries']);
        if ($entryCount < 10) {
            return AgentResult::fail(sprintf(
                'Strategist returned only %d entries — a usable monthly plan needs at least 10. Try again.',
                $entryCount,
            ));
        }

        $calendar = DB::transaction(function () use ($brand, $payload, $pillarMix, $formatMix, $activePlatforms, $startsOn, $endsOn) {
            $calendar = ContentCalendar::create([
                'brand_id' => $brand->id,
                'label' => $payload['period_label'] ?? $startsOn->format('F Y'),
                'period_starts_on' => $startsOn,
                'period_ends_on' => $endsOn,
                'pillar_mix' => $pillarMix,
                'format_mix' => $formatMix,
                'platform_mix' => array_fill_keys($activePlatforms, 1.0 / count($activePlatforms)),
                'status' => 'in_review',
                'created_by_user_id' => auth()->id(),
            ]);

            foreach ($payload['entries'] as $entry) {
                $platforms = array_values(array_intersect($entry['platforms'] ?? [], $activePlatforms))
                    ?: [$activePlatforms[0]];

                // Clamp day_offset to [0, daysInMonth-1]. Schema-side
                // minimum/maximum was removed (Anthropic rejects them on
                // integer types) so we enforce the range here.
                $offset = max(0, min(
                    $startsOn->daysInMonth - 1,
                    (int) ($entry['day_offset'] ?? 0),
                ));

                // Creative intent (target_emotion + content_angle) is folded
                // into research_brief.creative — no migration. ResearcherAgent
                // merges (preserves) this key when it later writes angles, and
                // the Writer/Designer read it for emotional + hook direction.
                $creative = array_filter([
                    'content_angle' => isset($entry['content_angle']) ? (string) $entry['content_angle'] : null,
                    'target_emotion' => isset($entry['target_emotion']) ? (string) $entry['target_emotion'] : null,
                ], fn ($v) => $v !== null && $v !== '');

                CalendarEntry::create([
                    'content_calendar_id' => $calendar->id,
                    'brand_id' => $brand->id,
                    'scheduled_date' => $startsOn->copy()->addDays($offset),
                    'scheduled_time' => null,
                    'topic' => substr($entry['topic'] ?? 'Untitled', 0, 250),
                    'angle' => $entry['angle'] ?? '',
                    'pillar' => $entry['pillar'] ?? 'educational',
                    'format' => $entry['format'] ?? 'single_image',
                    'platforms' => $platforms,
                    'objective' => $entry['objective'] ?? 'engagement',
                    'visual_direction' => $entry['visual_direction'] ?? null,
                    'research_brief' => $creative ? ['creative' => $creative] : null,
                    'status' => 'planned',
                ]);
            }

            return $calendar;
        });

        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'calendar_id' => $calendar->id,
            'label' => $calendar->label,
            'entry_count' => $calendar->entries()->count(),
            'period_starts_on' => $calendar->period_starts_on->toDateString(),
            'period_ends_on' => $calendar->period_ends_on->toDateString(),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    private function buildUserMessage(
        Brand $brand,
        string $brandStyleMd,
        array $platforms,
        array $pillarMix,
        array $formatMix,
        Carbon $startsOn,
        Carbon $endsOn,
        string $competitorBlock = '',
    ): string {
        $platformList = implode(', ', $platforms);
        $pillarLines = collect($pillarMix)->map(fn ($pct, $key) => "- $key: ".round($pct * 100)."%")->implode("\n");
        $formatLines = collect($formatMix)->map(fn ($pct, $key) => "- $key: ".round($pct * 100)."%")->implode("\n");

        $competitorSection = $competitorBlock !== ''
            ? "\n# Competitor signals (last 30 days)\n".$competitorBlock."\n"
            : '';

        // Synthesised strategic reads (self-suppress to '' when no current brief),
        // injected between the raw competitor signals and the brand facts. An
        // un-enriched brand with no briefs produces a prompt byte-identical to
        // the pre-feature behaviour.
        $competitorStrategyBlock = $this->renderCompetitorStrategy($brand);
        $competitorStrategySection = $competitorStrategyBlock === '' ? '' : "\n".$competitorStrategyBlock."\n";

        $marketTrendBlock = $this->renderMarketTrendBrief($brand);
        $marketTrendSection = $marketTrendBlock === '' ? '' : "\n".$marketTrendBlock."\n";

        $growthBlock = $this->renderGrowthStrategy($brand);
        $growthSection = $growthBlock === '' ? '' : "\n".$growthBlock."\n";

        // Goal-lagging pivot: when a growth goal is behind the pace it needs to
        // hit its target by its deadline, tell the Strategist to skew the month
        // toward the metric it targets. Suppressed to '' when nothing is lagging
        // (or no goals/readings exist), so on-track brands plan unchanged.
        $lagBlock = $this->renderLaggingGoals($brand);
        $lagSection = $lagBlock === '' ? '' : "\n".$lagBlock."\n";

        // Cross-month memory: the topics/angles this brand has ACTUALLY published
        // recently, so the Strategist plans fresh ground instead of re-covering
        // last month. Self-suppresses to '' for a brand with no published history
        // (a brand-new account's prompt stays byte-identical to the pre-feature
        // behaviour). Splices right before brand-style.md so it reads as a hard
        // exclusion list the model carries into planning.
        $recentBlock = $this->renderRecentlyPublished($brand);
        $recentSection = $recentBlock === '' ? '' : "\n".$recentBlock."\n";

        $factsBlock = $brand->brandFactsBlock();
        $factsSection = $factsBlock === '' ? '' : "\n".$factsBlock."\n";

        // Legal rules for this brand's industry + jurisdiction, injected so the
        // calendar is PLANNED compliant (shift-left). Suppressed (byte-identical
        // to the pre-feature prompt) when no curated rules apply.
        $legalBlock = $this->renderLegalRules($brand);
        $legalSection = $legalBlock === '' ? '' : "\n".$legalBlock."\n";

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}
PERIOD: {$startsOn->format('F j')} – {$endsOn->format('F j, Y')} ({$startsOn->daysInMonth} days)
TIMEZONE: {$brand->timezone}
ACTIVE PLATFORMS: {$platformList}

# Pillar mix targets
{$pillarLines}

# Format mix targets
{$formatLines}
{$competitorSection}{$competitorStrategySection}{$marketTrendSection}{$growthSection}{$lagSection}{$recentSection}{$factsSection}{$legalSection}
# brand-style.md (single source of truth)
{$brandStyleMd}

Plan the calendar now. Return one entry per day for the month, distributed across pillars/formats per the mix targets and across the active platforms. Every entry's topic and angle MUST be lawful for this industry in this jurisdiction — do not plan angles that require claims the legal rules above forbid. Use day_offset = 0 for the first day of the period.
MSG;
    }

    /**
     * Legal & advertising-standards directive for this brand's industry +
     * jurisdiction. Resolves the brand's catalog industry key and primary
     * jurisdiction, then delegates to the provider. Empty string when no curated
     * rules apply, so the prompt section is suppressed — keeping an un-curated
     * brand's prompt byte-identical to the pre-feature behaviour.
     */
    private function renderLegalRules(Brand $brand): string
    {
        return app(LegalRulesProvider::class)->promptDirectiveFor(
            $brand->industryKey(),
            $brand->primaryJurisdiction(),
        );
    }

    /**
     * Render the most recent competitor ads as a compact, themed block the
     * Strategist can reason over. Empty string when there's nothing in the
     * 30-day window — the prompt section is then suppressed entirely so the
     * model isn't reading a stub header with no data.
     *
     * Why we cap to 12 ads (not all 250 a brand might have): token budget +
     * the model needs themes, not a database dump. We show the freshest 12,
     * truncated to ~280 chars each, mixing platforms.
     */
    private function renderCompetitorSignals(Brand $brand): string
    {
        $rows = CompetitorAd::query()
            ->where('brand_id', $brand->id)
            ->where('expires_at', '>', now())
            ->whereNotNull('body')
            ->orderByDesc('observed_at')
            ->limit(12)
            ->get(['platform', 'competitor_label', 'competitor_handle', 'body', 'cta', 'first_seen_at']);

        if ($rows->isEmpty()) return '';

        $lines = $rows->map(function (CompetitorAd $ad) {
            $label = $ad->competitor_label ?: $ad->competitor_handle;
            $body = mb_substr(trim((string) $ad->body), 0, 280);
            $cta = $ad->cta ? " [CTA: {$ad->cta}]" : '';
            $when = $ad->first_seen_at?->format('M j') ?? 'recent';
            return "- ({$ad->platform} · {$label} · {$when}) {$body}{$cta}";
        })->implode("\n");

        return $lines."\n\nUse these as MARKET CONTEXT only — never copy themes, names, or wording. Position 1–2 entries as deliberate counter-positioning anchored in your brand's evidence.";
    }

    /**
     * Render the current competitor-strategy synthesis (Dim 2) — the strategic
     * READ of competitors' pillars, positioning, share-of-voice, and the
     * whitespace the brand can own. Returns '' when no current brief exists, so
     * the prompt section is suppressed entirely (no empty header).
     *
     * Written weekly by CompetitorStrategistAgent. share_of_voice here is
     * already evidence-true (recomputed in PHP from real ad counts).
     */
    private function renderCompetitorStrategy(Brand $brand): string
    {
        $brief = CompetitorStrategyBrief::currentForBrand($brand->id)->first();
        if (! $brief) {
            return '';
        }

        return self::renderCompetitorStrategyBlock(
            (array) ($brief->dominant_themes ?? []),
            (array) ($brief->share_of_voice ?? []),
            (array) ($brief->positioning_map ?? []),
            (array) ($brief->whitespace ?? []),
        );
    }

    /**
     * Pure renderer for the competitor-strategy block — no DB, no model state,
     * so it is unit-testable without a database (the suite runs DB-free).
     * Returns '' when every section is empty (suppression).
     *
     * @param  array<int,array<string,mixed>>  $themes
     * @param  array<string,mixed>             $shareOfVoice  label => pct
     * @param  array<int,array<string,mixed>>  $positioning
     * @param  array<int,string>               $whitespace
     */
    public static function renderCompetitorStrategyBlock(
        array $themes,
        array $shareOfVoice,
        array $positioning,
        array $whitespace,
    ): string {
        $lines = [];

        if ($themes !== []) {
            $themeLines = [];
            foreach (array_slice($themes, 0, 6) as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $theme = trim((string) ($t['theme'] ?? ''));
                if ($theme === '') {
                    continue;
                }
                $who = implode(', ', array_slice((array) ($t['competitors'] ?? []), 0, 4));
                $themeLines[] = "- {$theme}".($who !== '' ? " (pushed by: {$who})" : '');
            }
            if ($themeLines !== []) {
                $lines[] = "## Dominant competitor themes\n".implode("\n", $themeLines);
            }
        }

        if ($shareOfVoice !== []) {
            $sovLine = collect($shareOfVoice)
                ->take(6)
                ->map(fn ($pct, $label) => "{$label} {$pct}%")
                ->implode(' · ');
            $lines[] = "## Share of voice (by observed ad volume)\n{$sovLine}";
        }

        if ($positioning !== []) {
            $posLines = [];
            foreach (array_slice($positioning, 0, 6) as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $label = trim((string) ($p['competitor_label'] ?? ''));
                $summary = trim((string) ($p['positioning_summary'] ?? ''));
                if ($label === '' || $summary === '') {
                    continue;
                }
                $posLines[] = "- {$label}: {$summary}";
            }
            if ($posLines !== []) {
                $lines[] = "## How each competitor positions\n".implode("\n", $posLines);
            }
        }

        if ($whitespace !== []) {
            $ws = implode('; ', array_slice($whitespace, 0, 6));
            $lines[] = "## WHITESPACE — no competitor is addressing these (your opening)\n{$ws}";
        }

        if ($lines === []) {
            return '';
        }

        return "# Competitor strategy synthesis (last 30 days)\n".implode("\n\n", $lines)
            ."\n\nUse this as STRATEGY CONTEXT: position the brand DISTINCTLY from the dominant themes, and aim 1–2 entries squarely at the whitespace. Never name competitors or claim their metrics in published copy.";
    }

    /**
     * Render the current market & trend brief (Dim 1+3) — verified market
     * context + the genuine trends the brand could authentically ride, each
     * grounded in a verified signal. Returns '' when no current brief exists
     * (or it had zero verified trends, in which case MarketIntelAgent wrote
     * nothing), so the section is suppressed.
     */
    private function renderMarketTrendBrief(Brand $brand): string
    {
        $brief = MarketTrendBrief::currentForBrand($brand->id)->first();
        if (! $brief) {
            return '';
        }

        return self::renderMarketTrendBlock(
            (string) ($brief->market_summary ?? ''),
            (array) ($brief->trends ?? []),
            (array) ($brief->seasonal_moments ?? []),
        );
    }

    /**
     * Pure renderer for the market & trend block — no DB. Returns '' when there
     * is nothing to say (suppression).
     *
     * @param  array<int,array<string,mixed>>  $trends
     * @param  array<int,array<string,mixed>>  $seasonal
     */
    public static function renderMarketTrendBlock(
        string $marketSummary,
        array $trends,
        array $seasonal,
    ): string {
        $lines = [];

        $summary = trim($marketSummary);
        if ($summary !== '') {
            $lines[] = $summary;
        }

        if ($trends !== []) {
            $trendLines = [];
            foreach (array_slice($trends, 0, 6) as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $name = trim((string) ($t['trend'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $why = trim((string) ($t['why_relevant'] ?? ''));
                $angle = trim((string) ($t['suggested_angle'] ?? ''));
                $line = "- {$name}";
                if ($why !== '') {
                    $line .= " — {$why}";
                }
                if ($angle !== '') {
                    $line .= " (angle: {$angle})";
                }
                $trendLines[] = $line;
            }
            if ($trendLines !== []) {
                $lines[] = "## Current trends to consider\n".implode("\n", $trendLines);
            }
        }

        if ($seasonal !== []) {
            $seasonalLines = [];
            foreach (array_slice($seasonal, 0, 6) as $m) {
                if (! is_array($m)) {
                    continue;
                }
                $moment = trim((string) ($m['moment'] ?? ''));
                if ($moment === '') {
                    continue;
                }
                $window = trim((string) ($m['window'] ?? ''));
                $why = trim((string) ($m['why_relevant'] ?? ''));
                $line = "- {$moment}";
                if ($window !== '') {
                    $line .= " ({$window})";
                }
                if ($why !== '') {
                    $line .= " — {$why}";
                }
                $seasonalLines[] = $line;
            }
            if ($seasonalLines !== []) {
                $lines[] = "## Upcoming seasonal/topical moments\n".implode("\n", $seasonalLines);
            }
        }

        if ($lines === []) {
            return '';
        }

        return "# Market & Trend brief (verified signals)\n".implode("\n\n", $lines)
            ."\n\nThese are VERIFIED market signals. You MAY align 2–4 entries to a listed trend WHERE it authentically fits the brand. Never assert a market statistic the brief did not supply, and never claim a trend is 'viral' or cite numbers not given here.";
    }

    /**
     * Render the current growth strategy brief (best times / platform focus /
     * winning hooks / follower momentum / recommended objective mix), computed
     * from the brand's OWN real performance. Returns '' when no current brief
     * exists, so the section is suppressed.
     */
    private function renderGrowthStrategy(Brand $brand): string
    {
        $brief = GrowthStrategyBrief::currentForBrand($brand->id)->first();
        if (! $brief) {
            return '';
        }

        return self::renderGrowthStrategyBlock(
            (array) ($brief->best_posting_times ?? []),
            (array) ($brief->platform_focus ?? []),
            (array) ($brief->hook_performance ?? []),
            (array) ($brief->follower_velocity ?? []),
            (array) ($brief->recommended_objective_mix ?? []),
        );
    }

    /**
     * Pure renderer for the growth strategy block — no DB. All numbers are
     * computed facts from the brief; the prompt instructs the model to honour
     * them without inventing new ones. Returns '' when nothing to say.
     *
     * @param  array<string,mixed>             $bestTimes        {platform: [{day_of_week,hour,...}]}
     * @param  array<string,mixed>             $platformFocus    {platform: {reach_share_pct,...}}
     * @param  array<int,array<string,mixed>>  $hookPerformance  [{hook_pattern,avg_engagement,...}]
     * @param  array<string,mixed>             $followerVelocity {network: {direction,net_new,...}}
     * @param  array<string,float>             $objectiveMix     {objective: pct}
     */
    public static function renderGrowthStrategyBlock(
        array $bestTimes,
        array $platformFocus,
        array $hookPerformance,
        array $followerVelocity,
        array $objectiveMix,
    ): string {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $lines = [];

        if ($bestTimes !== []) {
            $timeLines = [];
            foreach ($bestTimes as $platform => $buckets) {
                if (! is_array($buckets) || $buckets === []) {
                    continue;
                }
                $slots = [];
                foreach (array_slice($buckets, 0, 2) as $b) {
                    if (! is_array($b)) {
                        continue;
                    }
                    $dow = (int) ($b['day_of_week'] ?? 0);
                    $hour = (int) ($b['hour'] ?? 0);
                    $slots[] = ($days[$dow] ?? '?')." {$hour}:00";
                }
                if ($slots !== []) {
                    $timeLines[] = "- {$platform}: ".implode(', ', $slots);
                }
            }
            if ($timeLines !== []) {
                $lines[] = "## Best posting times (schedule toward these)\n".implode("\n", $timeLines);
            }
        }

        if ($platformFocus !== []) {
            $focusLines = [];
            foreach ($platformFocus as $platform => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $share = $p['reach_share_pct'] ?? null;
                if ($share === null) {
                    continue;
                }
                $focusLines[] = "- {$platform}: {$share}% of reach";
            }
            if ($focusLines !== []) {
                $lines[] = "## Platform focus (by reach share — lean toward the leaders)\n".implode("\n", $focusLines);
            }
        }

        if ($hookPerformance !== []) {
            $hookLines = [];
            foreach (array_slice($hookPerformance, 0, 5) as $h) {
                if (! is_array($h)) {
                    continue;
                }
                $hook = trim((string) ($h['hook_pattern'] ?? ''));
                if ($hook === '') {
                    continue;
                }
                $win = isset($h['win_rate']) ? ' ('.round(((float) $h['win_rate']) * 100).'% win rate)' : '';
                $hookLines[] = "- {$hook}{$win}";
            }
            if ($hookLines !== []) {
                $lines[] = "## Winning hook patterns (favour these in content_angle)\n".implode("\n", $hookLines);
            }
        }

        if ($followerVelocity !== []) {
            $velLines = [];
            foreach ($followerVelocity as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $label = trim((string) ($v['label'] ?? ''));
                $dir = trim((string) ($v['direction'] ?? ''));
                if ($label === '' || $dir === '') {
                    continue;
                }
                $velLines[] = "- {$label}: {$dir}";
            }
            if ($velLines !== []) {
                $lines[] = "## Follower momentum\n".implode("\n", $velLines);
            }
        }

        if ($objectiveMix !== []) {
            $mixLine = collect($objectiveMix)
                ->map(fn ($pct, $obj) => "{$obj} ".round(((float) $pct) * 100).'%')
                ->implode(', ');
            if (trim($mixLine) !== '') {
                $lines[] = "## Recommended objective distribution\n{$mixLine}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "# Growth strategy (from this brand's own performance)\n".implode("\n\n", $lines)
            ."\n\nThese are computed from this brand's REAL metrics. Lean platform + objective distribution and posting times toward what's working here, and favour the winning hook patterns. Never assert a number not shown above.";
    }

    /**
     * Read the current growth brief's goal_progress and surface only the goals
     * whose pace_status is 'lagging' (computed by GrowthStrategistAgent from
     * real readings). Returns '' when no current brief, no goals, or nothing is
     * lagging, so on-track brands plan unchanged.
     */
    private function renderLaggingGoals(Brand $brand): string
    {
        $brief = GrowthStrategyBrief::currentForBrand($brand->id)->first();
        if (! $brief) {
            return '';
        }

        return self::renderLaggingGoalsBlock((array) ($brief->goal_progress ?? []));
    }

    /**
     * Pure renderer for the goals-behind-pace directive — no DB. Filters the
     * goal_progress rows to those flagged 'lagging' and turns each into a
     * concrete "bias toward X" line the Strategist acts on. Returns '' when
     * nothing is lagging (suppression). All numbers are restated from the brief
     * (real readings); none are invented.
     *
     * @param  array<int,array<string,mixed>>  $goalProgress  rows from GrowthStrategyBrief.goal_progress
     */
    public static function renderLaggingGoalsBlock(array $goalProgress): string
    {
        $lines = [];
        foreach ($goalProgress as $g) {
            if (! is_array($g) || ($g['pace_status'] ?? null) !== 'lagging') {
                continue;
            }
            $metric = trim((string) ($g['target_metric'] ?? ''));
            if ($metric === '') {
                continue;
            }
            $platform = trim((string) ($g['platform'] ?? ''));
            $scope = $platform !== '' ? " ({$platform})" : '';
            $progress = $g['progress_pct'] !== null ? round((float) $g['progress_pct']).'% reached' : 'progress not yet measurable';
            $expected = isset($g['expected_pct']) && $g['expected_pct'] !== null
                ? ', ~'.round((float) $g['expected_pct']).'% of the window elapsed'
                : '';

            $line = "- {$metric}{$scope}: {$progress}{$expected} — LAGGING.";
            $line .= "\n  → Over-index ".($platform !== '' ? "{$platform} in the platform split" : 'the platform(s) tied to this metric')
                ." and weight the objective mix toward the objective that drives {$metric}, using the brand's proven winning hooks for it.";
            $lines[] = $line;
        }

        if ($lines === []) {
            return '';
        }

        return "# Goals behind pace (bias the month toward closing these)\n".implode("\n", $lines)
            ."\n\nThese goals are behind the timeline they need to hit their target. Make closing them the priority for this month — skew platform distribution and objective mix toward the metric each one targets, even beyond the even reach-share split.";
    }

    /**
     * Read the topics/angles this brand has ACTUALLY published recently, sourced
     * from published ScheduledPosts → Draft → CalendarEntry (published_at is the
     * ground truth that something shipped — more reliable than CalendarEntry
     * status, which can lag). Deduped by topic, newest first, capped. Returns ''
     * when there's no published history in the window, so the section is
     * suppressed (a brand-new account's prompt stays byte-identical).
     *
     * @return string
     */
    private function renderRecentlyPublished(Brand $brand, int $days = 90, int $limit = 40): string
    {
        $since = Carbon::now()->subDays($days);

        // One row per published post with its planned topic/angle/pillar + the
        // real publish date. Join through drafts → calendar_entries so we read
        // the human-meaningful topic, not the raw caption body.
        $rows = DB::table('scheduled_posts as sp')
            ->join('drafts as d', 'd.id', '=', 'sp.draft_id')
            ->join('calendar_entries as ce', 'ce.id', '=', 'd.calendar_entry_id')
            ->where('sp.brand_id', $brand->id)
            ->where('sp.status', 'published')
            ->where('sp.published_at', '>=', $since)
            ->whereNotNull('ce.topic')
            ->orderByDesc('sp.published_at')
            ->limit(200) // bound the scan; dedup below trims to $limit distinct topics
            ->get(['ce.topic', 'ce.angle', 'ce.pillar', 'sp.published_at']);

        $entries = [];
        foreach ($rows as $r) {
            $topic = trim((string) ($r->topic ?? ''));
            if ($topic === '') {
                continue;
            }
            $entries[] = [
                'topic' => $topic,
                'angle' => trim((string) ($r->angle ?? '')),
                'pillar' => trim((string) ($r->pillar ?? '')),
                'published_at' => $r->published_at ? Carbon::parse($r->published_at) : null,
            ];
        }

        return self::renderRecentlyPublishedBlock($entries, $days, $limit);
    }

    /**
     * Pure renderer for the recently-published exclusion block — no DB, so it is
     * unit-testable without a database. Dedups by topic (case-insensitive),
     * keeps the first (newest) occurrence, caps at $limit lines. Returns '' when
     * there is nothing to exclude (suppression).
     *
     * @param  array<int,array{topic:string,angle?:string,pillar?:string,published_at?:?\Illuminate\Support\Carbon}>  $entries
     */
    public static function renderRecentlyPublishedBlock(array $entries, int $days = 90, int $limit = 40): string
    {
        $seen = [];
        $lines = [];
        foreach ($entries as $e) {
            $topic = trim((string) ($e['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $key = mb_strtolower($topic);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $pillar = trim((string) ($e['pillar'] ?? ''));
            $angle = trim((string) ($e['angle'] ?? ''));
            $when = ($e['published_at'] ?? null) instanceof Carbon ? $e['published_at']->format('M j') : null;

            $prefix = $when ? "- {$when} · " : '- ';
            $line = $prefix.($pillar !== '' ? "{$pillar} · " : '')."\"{$topic}\"";
            if ($angle !== '') {
                $line .= " (angle: {$angle})";
            }
            $lines[] = $line;

            if (count($lines) >= $limit) {
                break;
            }
        }

        if ($lines === []) {
            return '';
        }

        return "# Recently published — DO NOT REPEAT these topics or angles (last {$days} days)\n"
            .implode("\n", $lines)
            ."\n\nThis content already shipped for this brand. Plan topics and angles that are clearly DISTINCT from the list above. Re-using a PILLAR is fine (the mix targets require it); re-using a TOPIC or ANGLE is not — find a fresh take.";
    }
}
