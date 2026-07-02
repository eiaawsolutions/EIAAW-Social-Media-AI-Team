<?php

namespace Tests\Unit;

use App\Agents\Prompts\GrowthStrategistPrompt;
use App\Agents\Prompts\StrategistPrompt;
use App\Agents\Prompts\WriterPrompt;
use App\Agents\StrategistAgent;
use App\Agents\WriterAgent;
use App\Models\BrandGrowthGoal;
use App\Services\Growth\BestTimeResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for the Growth Strategist's rendering, the
 * best-time resolver, goal-progress math, and the prompt versions/sections.
 * Locks the suppression guarantee (empty → '' → byte-identical prompts).
 */
class GrowthStrategyRenderingTest extends TestCase
{
    // ── Strategist block ──────────────────────────────────────────────

    public function test_growth_block_suppresses_when_all_empty(): void
    {
        $this->assertSame('', StrategistAgent::renderGrowthStrategyBlock([], [], [], [], []));
    }

    public function test_growth_block_renders_each_section(): void
    {
        $block = StrategistAgent::renderGrowthStrategyBlock(
            bestTimes: ['instagram' => [['day_of_week' => 2, 'hour' => 8, 'avg_score' => 900]]],
            platformFocus: ['instagram' => ['reach_share_pct' => 70.0]],
            hookPerformance: [['hook_pattern' => 'authority_insight', 'win_rate' => 0.8]],
            followerVelocity: ['instagram' => ['label' => 'Instagram', 'direction' => 'accelerating']],
            objectiveMix: ['leads' => 0.3, 'awareness' => 0.2],
        );

        $this->assertStringContainsString("# Growth strategy (from this brand's own performance)", $block);
        $this->assertStringContainsString('Best posting times', $block);
        $this->assertStringContainsString('Tue 8:00', $block);
        $this->assertStringContainsString('70% of reach', $block);
        // Prompt block keeps the exact hook enum (the Writer reports hook_pattern
        // by that value) — only the dashboard humanises it.
        $this->assertStringContainsString('authority_insight (80% win rate)', $block);
        $this->assertStringContainsString('accelerating', $block);
        $this->assertStringContainsString('leads 30%', $block);
        $this->assertStringContainsString('Never assert a number not shown above', $block);
    }

    // ── Writer per-objective guidance ─────────────────────────────────

    public function test_writer_objective_guidance_suppresses_without_match(): void
    {
        // No guidance for this objective → ''.
        $this->assertSame('', WriterAgent::renderGrowthObjectiveGuidanceBlock([], 'leads'));
        $this->assertSame('', WriterAgent::renderGrowthObjectiveGuidanceBlock(
            ['awareness' => ['hook_patterns' => ['story'], 'cta_styles' => []]],
            'leads', // asking for leads, only awareness present
        ));
    }

    public function test_writer_objective_guidance_renders_hooks_and_ctas(): void
    {
        $lines = WriterAgent::renderGrowthObjectiveGuidanceBlock(
            ['leads' => ['hook_patterns' => ['authority_insight', 'transformation'], 'cta_styles' => ['Book a 15-min audit']]],
            'leads',
        );

        $this->assertStringContainsString('Proven hook patterns for this objective (leads): authority_insight, transformation', $lines);
        $this->assertStringContainsString('"Book a 15-min audit"', $lines);
        // Lines start with the same "\n- " idiom as renderCreativeIntent.
        $this->assertStringStartsWith("\n- ", $lines);
    }

    // ── BestTimeResolver ──────────────────────────────────────────────

    public function test_best_time_resolver_prefers_exact_day_then_falls_back(): void
    {
        $bestTimes = [
            'instagram' => [
                ['day_of_week' => 2, 'hour' => 8, 'avg_score' => 500],   // Tue 8am
                ['day_of_week' => 4, 'hour' => 19, 'avg_score' => 900],  // Thu 7pm (higher score, different day)
            ],
        ];

        // Asking for Tuesday → exact-day match wins even though Thu scores higher.
        $this->assertSame(8, BestTimeResolver::hourFor($bestTimes, 'instagram', 2));
        // Asking for a day with no exact bucket → overall best bucket (Thu 7pm).
        $this->assertSame(19, BestTimeResolver::hourFor($bestTimes, 'instagram', 0));
        // Unknown platform → null (caller keeps its own fallback).
        $this->assertNull(BestTimeResolver::hourFor($bestTimes, 'tiktok', 2));
        $this->assertNull(BestTimeResolver::hourFor([], 'instagram', 2));
    }

    // ── Goal progress ─────────────────────────────────────────────────

    public function test_goal_progress_measures_gain_over_baseline(): void
    {
        // baseline 4000, target 5000, current 4500 → 50% of the 1000-span gained.
        $this->assertSame(50.0, BrandGrowthGoal::progressPct(4000, 5000, 4500));
        // at/under baseline → 0%.
        $this->assertSame(0.0, BrandGrowthGoal::progressPct(4000, 5000, 4000));
        $this->assertSame(0.0, BrandGrowthGoal::progressPct(4000, 5000, 3900));
        // at/over target → clamped 100%.
        $this->assertSame(100.0, BrandGrowthGoal::progressPct(4000, 5000, 5200));
        // no reading → null (render "—").
        $this->assertNull(BrandGrowthGoal::progressPct(4000, 5000, null));
        // degenerate goal (target ≤ baseline) → null.
        $this->assertNull(BrandGrowthGoal::progressPct(5000, 5000, 5000));
    }

    // ── Prompt versions + sections ────────────────────────────────────

    public function test_growth_strategist_prompt_version_and_no_numeric_schema(): void
    {
        $this->assertSame('growth_strategist.v1.1', GrowthStrategistPrompt::VERSION);

        $system = GrowthStrategistPrompt::system();
        $this->assertStringContainsString('Do NOT output any numeric metric', $system);
        $this->assertStringContainsString('curiosity_gap', $system); // hook enum listed

        $schema = GrowthStrategistPrompt::schema();
        $props = $schema['properties'];
        $this->assertArrayHasKey('objective_guidance', $props);
        // No numeric metric fields — the system computes every number.
        $this->assertArrayNotHasKey('best_posting_times', $props);
        $this->assertArrayNotHasKey('cta_lift', $props);
        $this->assertArrayNotHasKey('hook_performance', $props);
    }

    public function test_strategist_prompt_bumped_with_growth_section(): void
    {
        $this->assertSame('strategist.v1.9', StrategistPrompt::VERSION);
        $this->assertStringContainsString('# Growth strategy', StrategistPrompt::system());
    }

    public function test_writer_prompt_bumped_with_growth_guidance_section(): void
    {
        // v1.6 introduced the Growth objective guidance section; v1.7 added the
        // anti-fabrication hardening. The growth section must still be present.
        $this->assertSame('writer.v1.7', WriterPrompt::VERSION);
        $this->assertStringContainsString('# Growth objective guidance', WriterPrompt::system('instagram'));
    }

    // ── Goal pace (goal-lagging pivot) ────────────────────────────────

    public function test_pace_status_classifies_lagging_on_track_and_ahead(): void
    {
        $start = Carbon::parse('2026-06-01 00:00:00');
        $end = Carbon::parse('2026-07-01 00:00:00');
        $mid = Carbon::parse('2026-06-16 00:00:00'); // exactly half-elapsed → expected 50%

        // 30% progress at the 50% mark → 20 points behind → lagging.
        $this->assertSame('lagging', BrandGrowthGoal::paceStatus(30.0, $start, $end, $mid));
        // 50% progress at the 50% mark → on track (within ±10pt tolerance).
        $this->assertSame('on_track', BrandGrowthGoal::paceStatus(50.0, $start, $end, $mid));
        // 75% progress at the 50% mark → 25 points ahead → ahead.
        $this->assertSame('ahead', BrandGrowthGoal::paceStatus(75.0, $start, $end, $mid));
    }

    public function test_pace_status_returns_null_without_a_reading_or_window(): void
    {
        $start = Carbon::parse('2026-06-01 00:00:00');
        $end = Carbon::parse('2026-07-01 00:00:00');
        $mid = Carbon::parse('2026-06-16 00:00:00');

        // No progress reading → null (never invent a verdict).
        $this->assertNull(BrandGrowthGoal::paceStatus(null, $start, $end, $mid));
        // Degenerate window (end ≤ start) → null.
        $this->assertNull(BrandGrowthGoal::paceStatus(50.0, $end, $start, $mid));
        // Before the window opens → null (no pace to judge yet).
        $this->assertNull(BrandGrowthGoal::paceStatus(50.0, $start, $end, Carbon::parse('2026-05-01 00:00:00')));
    }

    public function test_expected_pct_is_linear_elapsed_and_clamped(): void
    {
        $start = Carbon::parse('2026-06-01 00:00:00');
        $end = Carbon::parse('2026-07-01 00:00:00');

        $this->assertSame(50.0, BrandGrowthGoal::expectedPct($start, $end, Carbon::parse('2026-06-16 00:00:00')));
        // Past the deadline → clamped to 100 (now is clamped into the window).
        $this->assertSame(100.0, BrandGrowthGoal::expectedPct($start, $end, Carbon::parse('2026-08-01 00:00:00')));
        // Before the window / degenerate → null.
        $this->assertNull(BrandGrowthGoal::expectedPct($start, $end, Carbon::parse('2026-05-01 00:00:00')));
        $this->assertNull(BrandGrowthGoal::expectedPct($end, $start, Carbon::parse('2026-06-16 00:00:00')));
    }

    // ── Lagging-goals Strategist block ────────────────────────────────

    public function test_lagging_goals_block_suppresses_when_nothing_lagging(): void
    {
        $this->assertSame('', StrategistAgent::renderLaggingGoalsBlock([]));
        // An on-track goal produces no pressure.
        $this->assertSame('', StrategistAgent::renderLaggingGoalsBlock([
            ['target_metric' => 'followers', 'platform' => 'instagram', 'pace_status' => 'on_track', 'progress_pct' => 55.0],
        ]));
    }

    public function test_lagging_goals_block_renders_only_lagging_and_biases_metric(): void
    {
        $block = StrategistAgent::renderLaggingGoalsBlock([
            ['target_metric' => 'followers', 'platform' => 'instagram', 'pace_status' => 'lagging', 'progress_pct' => 30.0, 'expected_pct' => 60.0],
            ['target_metric' => 'reach', 'platform' => 'tiktok', 'pace_status' => 'ahead', 'progress_pct' => 90.0, 'expected_pct' => 50.0],
        ]);

        $this->assertStringContainsString('# Goals behind pace', $block);
        $this->assertStringContainsString('followers (instagram)', $block);
        $this->assertStringContainsString('LAGGING', $block);
        $this->assertStringContainsString('Over-index instagram', $block);
        // Restated numbers, not invented ones.
        $this->assertStringContainsString('30% reached', $block);
        $this->assertStringContainsString('60% of the window elapsed', $block);
        // The ahead goal is NOT pressured.
        $this->assertStringNotContainsString('tiktok', $block);
    }

    // ── Recently-published anti-recycling block ───────────────────────

    public function test_recently_published_block_suppresses_when_empty(): void
    {
        $this->assertSame('', StrategistAgent::renderRecentlyPublishedBlock([]));
    }

    public function test_recently_published_block_dedups_by_topic_and_lists_exclusions(): void
    {
        $block = StrategistAgent::renderRecentlyPublishedBlock([
            ['topic' => 'Latte art for beginners', 'pillar' => 'educational', 'angle' => 'step-by-step', 'published_at' => Carbon::parse('2026-06-05')],
            ['topic' => 'Meet our weekend barista', 'pillar' => 'community', 'published_at' => Carbon::parse('2026-06-02')],
            // Duplicate topic (different case) → collapsed to the first occurrence.
            ['topic' => 'latte ART for beginners', 'pillar' => 'educational', 'published_at' => Carbon::parse('2026-05-20')],
        ]);

        $this->assertStringContainsString('# Recently published — DO NOT REPEAT', $block);
        $this->assertStringContainsString('Jun 5 · educational · "Latte art for beginners"', $block);
        $this->assertStringContainsString('(angle: step-by-step)', $block);
        $this->assertStringContainsString('Meet our weekend barista', $block);
        // Reusing a pillar stays explicitly allowed; only the topic/angle is barred.
        $this->assertStringContainsString('Re-using a PILLAR is fine', $block);
        // Deduped: only ONE latte-art line.
        $this->assertSame(1, substr_count(strtolower($block), 'latte art for beginners'));
    }

    public function test_recently_published_block_respects_limit(): void
    {
        $entries = [];
        for ($i = 1; $i <= 50; $i++) {
            $entries[] = ['topic' => "Topic number {$i}", 'pillar' => 'educational'];
        }
        $block = StrategistAgent::renderRecentlyPublishedBlock($entries, 90, 40);

        // 40 listed, 41+ trimmed.
        $this->assertStringContainsString('"Topic number 40"', $block);
        $this->assertStringNotContainsString('"Topic number 41"', $block);
    }
}
