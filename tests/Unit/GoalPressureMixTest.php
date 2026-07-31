<?php

namespace Tests\Unit;

use App\Services\Growth\GoalPressureMix;
use Tests\TestCase;

/**
 * Pure tests for L2 mix actuation. The invariants here are what keep an
 * "aggressive" system from being a destructive one: skew is a bounded transfer,
 * never an inflation, and no surface is ever starved to zero.
 */
class GoalPressureMixTest extends TestCase
{
    private const EVEN_FOUR = [
        'instagram' => 0.25,
        'linkedin' => 0.25,
        'tiktok' => 0.25,
        'threads' => 0.25,
    ];

    public function test_no_pressure_leaves_mix_unchanged(): void
    {
        $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, []);
        $this->assertEqualsWithDelta(0.25, $out['instagram'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $out['linkedin'], 0.0001);
    }

    public function test_pressure_moves_weight_to_the_goal_platform(): void
    {
        $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => 1.0]);

        $this->assertGreaterThan(0.25, $out['instagram']);
        $this->assertLessThan(0.25, $out['linkedin']);
    }

    public function test_result_always_sums_to_one(): void
    {
        foreach ([0.1, 0.4, 0.75, 1.0] as $p) {
            $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => $p]);
            $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001, "sum broke at pressure {$p}");
        }
    }

    public function test_skew_is_monotonic_in_pressure(): void
    {
        $low = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => 0.2]);
        $high = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => 0.9]);

        $this->assertGreaterThan($low['instagram'], $high['instagram']);
    }

    public function test_no_surface_is_starved_below_the_floor(): void
    {
        // The invariant OptimizerAgent depends on: a platform that drops to zero
        // posts can never demonstrate it has recovered.
        $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => 1.0]);

        foreach ($out as $platform => $weight) {
            $this->assertGreaterThanOrEqual(
                GoalPressureMix::MIN_WEIGHT - 0.0001,
                $weight,
                "{$platform} was starved below the floor",
            );
        }
    }

    public function test_total_transfer_is_capped(): void
    {
        $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['instagram' => 1.0]);
        $gained = $out['instagram'] - 0.25;

        $this->assertLessThanOrEqual(GoalPressureMix::MAX_TOTAL_SKEW + 0.0001, $gained);
    }

    public function test_multiple_pressured_platforms_share_the_capped_budget(): void
    {
        $out = GoalPressureMix::skewPlatformMix(
            self::EVEN_FOUR,
            ['instagram' => 1.0, 'threads' => 1.0],
        );

        $gained = ($out['instagram'] - 0.25) + ($out['threads'] - 0.25);
        $this->assertLessThanOrEqual(GoalPressureMix::MAX_TOTAL_SKEW + 0.0001, $gained);
        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001);
    }

    public function test_goal_platform_absent_from_mix_is_ignored(): void
    {
        // A goal on a platform with no active connection must not conjure a
        // share — that would plan posts to a surface we cannot publish to.
        $out = GoalPressureMix::skewPlatformMix(self::EVEN_FOUR, ['youtube' => 1.0]);

        $this->assertArrayNotHasKey('youtube', $out);
        $this->assertEqualsWithDelta(0.25, $out['instagram'], 0.0001);
    }

    public function test_all_platforms_pressured_leaves_mix_unchanged(): void
    {
        // No donors exist, so there is nothing to transfer. Refusing to act is
        // correct — the prior mix is still a valid plan.
        $out = GoalPressureMix::skewPlatformMix(
            self::EVEN_FOUR,
            array_fill_keys(array_keys(self::EVEN_FOUR), 1.0),
        );

        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001);
        $this->assertEqualsWithDelta(0.25, $out['instagram'], 0.0001);
    }

    public function test_donors_already_at_floor_cannot_give(): void
    {
        $mix = ['instagram' => 0.85, 'linkedin' => 0.05, 'tiktok' => 0.05, 'threads' => 0.05];
        $out = GoalPressureMix::skewPlatformMix($mix, ['instagram' => 1.0]);

        $this->assertEqualsWithDelta(0.85, $out['instagram'], 0.001, 'nothing was available to transfer');
        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001);
    }

    public function test_empty_mix_returns_empty(): void
    {
        $this->assertSame([], GoalPressureMix::skewPlatformMix([], ['instagram' => 1.0]));
    }

    public function test_objective_skew_shares_the_same_guarantees(): void
    {
        $mix = ['awareness' => 0.2, 'engagement' => 0.2, 'traffic' => 0.2, 'leads' => 0.2, 'retention' => 0.2];
        $out = GoalPressureMix::skewObjectiveMix($mix, ['awareness' => 0.8]);

        $this->assertGreaterThan(0.2, $out['awareness']);
        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001);
        foreach ($out as $weight) {
            $this->assertGreaterThanOrEqual(GoalPressureMix::MIN_WEIGHT - 0.0001, $weight);
        }
    }

    public function test_normalise_drops_non_positive_weights(): void
    {
        $out = GoalPressureMix::normalise(['a' => 0.5, 'b' => 0.0, 'c' => -0.2, 'd' => 0.5]);

        $this->assertSame(['a', 'd'], array_keys($out));
        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.001);
    }
}
