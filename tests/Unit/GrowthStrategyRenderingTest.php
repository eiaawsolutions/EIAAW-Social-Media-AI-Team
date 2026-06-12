<?php

namespace Tests\Unit;

use App\Agents\Prompts\GrowthStrategistPrompt;
use App\Agents\Prompts\StrategistPrompt;
use App\Agents\Prompts\WriterPrompt;
use App\Agents\StrategistAgent;
use App\Agents\WriterAgent;
use App\Models\BrandGrowthGoal;
use App\Services\Growth\BestTimeResolver;
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
        $this->assertSame('growth_strategist.v1.0', GrowthStrategistPrompt::VERSION);

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

    public function test_strategist_prompt_bumped_to_v16_with_growth_section(): void
    {
        $this->assertSame('strategist.v1.6', StrategistPrompt::VERSION);
        $this->assertStringContainsString('# Growth strategy', StrategistPrompt::system());
    }

    public function test_writer_prompt_bumped_to_v16_with_growth_guidance_section(): void
    {
        $this->assertSame('writer.v1.6', WriterPrompt::VERSION);
        $this->assertStringContainsString('# Growth objective guidance', WriterPrompt::system('instagram'));
    }
}
