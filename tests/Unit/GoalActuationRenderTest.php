<?php

namespace Tests\Unit;

use App\Agents\GrowthStrategistAgent;
use App\Agents\StrategistAgent;
use App\Models\BrandGrowthGoal;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Services\Growth\GrowthPressure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure tests for the parts of the loop that turn pressure into something the
 * planner can act on: the pressure maps that drive L2, the prompt blocks that
 * carry magnitude, the cold-start guard, and the exclusion of confounded
 * scheduling data from the best-time learner. No DB.
 */
class GoalActuationRenderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function goalRow(array $overrides = []): array
    {
        return array_merge([
            'goal_id' => 1,
            'target_metric' => 'followers',
            'platform' => 'instagram',
            'target_value' => 10000,
            'baseline_value' => 7,
            'current_value' => 9,
            'progress_pct' => 0.0,
            'expected_pct' => 47.4,
            'pace_status' => 'lagging',
            'lagging_streak' => 6,
            'days_remaining' => 42,
            'pressure' => 0.82,
            'rung' => GrowthPressure::RUNG_DISTRIBUTION,
            'feasibility_verdict' => BrandGrowthGoal::FEASIBILITY_STRETCH,
            'window_ends_on' => '2026-09-12',
        ], $overrides);
    }

    // ── pressure maps (L2 input) ────────────────────────────────────────

    public function test_pressure_map_extracts_platform_and_objective(): void
    {
        $maps = StrategistAgent::pressureMapsFrom([$this->goalRow()]);

        $this->assertSame(['instagram' => 0.82], $maps['platform']);
        $this->assertSame(['awareness' => 0.82], $maps['objective'], 'followers maps to awareness');
    }

    public function test_goals_below_the_mix_rung_do_not_actuate(): void
    {
        // L1 is prompt-only. Mechanically moving the mix for a goal barely off
        // pace would make the plan jitter week to week.
        $maps = StrategistAgent::pressureMapsFrom([
            $this->goalRow(['pressure' => 0.12, 'rung' => GrowthPressure::RUNG_PROMPT]),
        ]);

        $this->assertSame([], $maps['platform']);
        $this->assertSame([], $maps['objective']);
    }

    public function test_unmeasured_goal_contributes_no_pressure(): void
    {
        $maps = StrategistAgent::pressureMapsFrom([
            $this->goalRow(['pressure' => null, 'rung' => 0, 'progress_pct' => null, 'pace_status' => null]),
        ]);

        $this->assertSame([], $maps['platform']);
    }

    public function test_infeasible_goal_does_not_consume_the_mix_budget(): void
    {
        // It still gets narrated at L1, but it must not bend the month toward a
        // number no plan can reach — that budget belongs to closeable goals.
        $maps = StrategistAgent::pressureMapsFrom([
            $this->goalRow(['feasibility_verdict' => BrandGrowthGoal::FEASIBILITY_INFEASIBLE]),
        ]);

        $this->assertSame([], $maps['platform']);
    }

    public function test_max_rung_takes_the_highest(): void
    {
        $rows = [
            $this->goalRow(['rung' => GrowthPressure::RUNG_PROMPT]),
            $this->goalRow(['rung' => GrowthPressure::RUNG_SCHEDULE]),
        ];

        $this->assertSame(GrowthPressure::RUNG_SCHEDULE, StrategistAgent::maxRung($rows));
        $this->assertSame(GrowthPressure::RUNG_NONE, StrategistAgent::maxRung([]));
    }

    // ── lagging block (L1 text) ─────────────────────────────────────────

    public function test_lagging_block_carries_magnitude_and_streak(): void
    {
        // The defect: 11 points behind and 47 points behind used to render
        // byte-identical text.
        $block = StrategistAgent::renderLaggingGoalsBlock([$this->goalRow()]);

        $this->assertStringContainsString('6 consecutive checks', $block);
        $this->assertStringContainsString('42 days left', $block);
        $this->assertStringContainsString('L4 distribution', $block);
    }

    public function test_lagging_block_differs_by_severity(): void
    {
        $mild = StrategistAgent::renderLaggingGoalsBlock([
            $this->goalRow(['progress_pct' => 40.0, 'lagging_streak' => 1, 'pressure' => 0.14, 'rung' => GrowthPressure::RUNG_PROMPT]),
        ]);
        $severe = StrategistAgent::renderLaggingGoalsBlock([$this->goalRow()]);

        $this->assertNotSame($mild, $severe, 'severity must change the instruction, not just the numbers');
    }

    public function test_top_rung_adds_a_distribution_directive(): void
    {
        $block = StrategistAgent::renderLaggingGoalsBlock([$this->goalRow()]);
        $this->assertStringContainsString('DISTRIBUTION', $block);
    }

    public function test_mix_rung_tells_the_model_weights_already_moved(): void
    {
        // Without this the model skews a second time on top of the mechanical
        // transfer and over-concentrates the month.
        $block = StrategistAgent::renderLaggingGoalsBlock([$this->goalRow()]);
        $this->assertStringContainsString('ALREADY been shifted', $block);
    }

    public function test_infeasible_goal_is_narrated_honestly(): void
    {
        $block = StrategistAgent::renderLaggingGoalsBlock([
            $this->goalRow(['feasibility_verdict' => BrandGrowthGoal::FEASIBILITY_INFEASIBLE]),
        ]);

        $this->assertStringContainsString('NOT reachable', $block);
        $this->assertStringContainsString('largest honest gain', $block);
    }

    public function test_nothing_lagging_suppresses_the_block(): void
    {
        $this->assertSame('', StrategistAgent::renderLaggingGoalsBlock([
            $this->goalRow(['pace_status' => 'on_track']),
        ]));
        $this->assertSame('', StrategistAgent::renderLaggingGoalsBlock([]));
    }

    // ── growth block (magnitude, step 3) ────────────────────────────────

    public function test_growth_block_shows_follower_magnitude_not_just_direction(): void
    {
        // "flat at 9 followers" and "flat at 90,000" need opposite strategies;
        // the block used to print bare "flat" for both.
        $block = StrategistAgent::renderGrowthStrategyBlock([], [], [], [
            'instagram' => ['label' => 'Instagram', 'direction' => 'flat', 'latest' => 90000, 'net_new' => 0, 'cold_start' => false],
        ], []);

        $this->assertStringContainsString('90000 followers', $block);
    }

    public function test_cold_start_triggers_a_different_playbook(): void
    {
        $block = StrategistAgent::renderGrowthStrategyBlock([], [], [], [
            'instagram' => ['label' => 'Instagram', 'direction' => 'cold_start', 'latest' => 9, 'net_new' => 0, 'cold_start' => true],
        ], []);

        $this->assertStringContainsString('cold start', $block);
        $this->assertStringContainsString('9 followers', $block);
        $this->assertStringContainsString('do NOT already follow', $block);
    }

    public function test_growth_block_still_suppresses_when_empty(): void
    {
        $this->assertSame('', StrategistAgent::renderGrowthStrategyBlock([], [], [], [], []));
    }

    // ── cold-start classifier (step 3) ──────────────────────────────────

    public function test_small_account_is_flagged_cold_start_not_given_a_momentum_verb(): void
    {
        // At a base of 9, one follower is +12.5% — the old classifier called
        // that "accelerating" and handed it to the planner as momentum.
        $out = GrowthStrategistAgent::classifyFollowerVelocity([
            'followers' => [
                'networks' => [
                    ['network' => 'instagram', 'label' => 'Instagram', 'status' => 'ok', 'headline' => 9, 'change' => 1],
                ],
            ],
        ]);

        $this->assertTrue($out['instagram']['cold_start']);
        $this->assertSame('cold_start', $out['instagram']['direction']);
        $this->assertSame(1, $out['instagram']['net_new'], 'the raw count is still reported');
    }

    public function test_account_above_the_floor_keeps_percentage_classification(): void
    {
        $out = GrowthStrategistAgent::classifyFollowerVelocity([
            'followers' => [
                'networks' => [
                    ['network' => 'instagram', 'label' => 'Instagram', 'status' => 'ok', 'headline' => 1020, 'change' => 20],
                ],
            ],
        ]);

        $this->assertFalse($out['instagram']['cold_start']);
        $this->assertSame('accelerating', $out['instagram']['direction']);
    }

    public function test_cold_start_floor_boundary(): void
    {
        $at = GrowthStrategistAgent::classifyFollowerVelocity([
            'followers' => ['networks' => [
                ['network' => 'x', 'label' => 'X', 'status' => 'ok', 'headline' => GrowthStrategistAgent::COLD_START_FOLLOWERS, 'change' => 0],
            ]],
        ]);
        $below = GrowthStrategistAgent::classifyFollowerVelocity([
            'followers' => ['networks' => [
                ['network' => 'x', 'label' => 'X', 'status' => 'ok', 'headline' => GrowthStrategistAgent::COLD_START_FOLLOWERS - 1, 'change' => 0],
            ]],
        ]);

        $this->assertFalse($at['x']['cold_start'], 'the floor itself is not cold start');
        $this->assertTrue($below['x']['cold_start']);
    }

    // ── best-time learner excludes confounded rows (step 1) ─────────────

    public function test_late_posts_are_excluded_from_the_best_time_learner(): void
    {
        // A past_slot_fallback post published at 03:00 records when our pipeline
        // caught up, not when the audience was there. Left in, it taught the
        // learner that being late was optimal.
        $rows = new Collection([
            $this->metric('instagram', '2026-07-06 08:00:00', ScheduledPost::SCHEDULING_EXPLORE, 100),
            $this->metric('instagram', '2026-07-13 08:00:00', ScheduledPost::SCHEDULING_EXPLORE, 100),
            $this->metric('instagram', '2026-07-07 03:00:00', ScheduledPost::SCHEDULING_PAST_SLOT_FALLBACK, 100000),
            $this->metric('instagram', '2026-07-14 03:00:00', ScheduledPost::SCHEDULING_PAST_SLOT_FALLBACK, 100000),
        ]);

        $out = GrowthStrategistAgent::computeBestPostingTimes($rows, 2);

        $hours = array_column($out['instagram'] ?? [], 'hour');
        $this->assertContains(8, $hours);
        $this->assertNotContains(3, $hours, 'a late-publish hour is not evidence about audience timing');
    }

    public function test_unlabelled_legacy_rows_remain_admissible(): void
    {
        // Rows written before the column existed have unknown provenance.
        // Back-filling a guess would be fabricating it; only the explicitly
        // confounded value is excluded.
        $rows = new Collection([
            $this->metric('linkedin', '2026-07-06 15:00:00', null, 300),
            $this->metric('linkedin', '2026-07-13 15:00:00', null, 300),
        ]);

        $out = GrowthStrategistAgent::computeBestPostingTimes($rows, 2);

        $this->assertContains(15, array_column($out['linkedin'] ?? [], 'hour'));
    }

    private function metric(string $platform, string $publishedAt, ?string $strategy, int $impressions): PostMetric
    {
        $post = new ScheduledPost;
        $post->published_at = Carbon::parse($publishedAt);
        $post->scheduling_strategy = $strategy;

        $m = new PostMetric;
        $m->platform = $platform;
        $m->impressions = $impressions;
        $m->setRelation('scheduledPost', $post);

        return $m;
    }
}
