<?php

namespace Tests\Unit;

use App\Services\Growth\GrowthPressure;
use Tests\TestCase;

/**
 * Pure tests for the continuous pressure scalar that replaces the 3-value
 * paceStatus verdict as the escalation input. No DB, no clock.
 *
 * The two properties that matter most here are the ones the old verdict lacked:
 * MONOTONICITY (a bigger gap must produce more pressure) and TRUTHFULNESS (no
 * reading must produce no pressure, never a default).
 */
class GrowthPressureTest extends TestCase
{
    public function test_no_reading_yields_null_pressure_not_zero(): void
    {
        // Distinct from 0.0: 0.0 means "measured, on pace"; null means "unknown".
        // Collapsing them would let an unmeasurable goal look healthy.
        $this->assertNull(GrowthPressure::score(null, 47.4));
        $this->assertNull(GrowthPressure::score(12.0, null));
        $this->assertNull(GrowthPressure::score(null, null));
    }

    public function test_on_pace_or_ahead_yields_zero(): void
    {
        $this->assertSame(0.0, GrowthPressure::score(50.0, 50.0));
        $this->assertSame(0.0, GrowthPressure::score(80.0, 50.0));
    }

    public function test_pressure_is_monotonic_in_the_gap(): void
    {
        // This is the defect the old verdict had: 11 points behind and 47 points
        // behind both read 'lagging' and produced identical downstream text.
        $small = GrowthPressure::score(40.0, 51.0);
        $large = GrowthPressure::score(0.0, 47.4);

        $this->assertNotNull($small);
        $this->assertNotNull($large);
        $this->assertGreaterThan($small, $large);
    }

    public function test_streak_increases_pressure_and_caps(): void
    {
        $fresh = GrowthPressure::score(20.0, 50.0, 0);
        $stale = GrowthPressure::score(20.0, 50.0, 3);
        $ancient = GrowthPressure::score(20.0, 50.0, GrowthPressure::STREAK_CAP);
        $beyond = GrowthPressure::score(20.0, 50.0, GrowthPressure::STREAK_CAP + 40);

        $this->assertGreaterThan($fresh, $stale);
        $this->assertGreaterThan($stale, $ancient);
        $this->assertSame($ancient, $beyond, 'streak must stop compounding at the cap');
    }

    public function test_urgency_increases_as_deadline_approaches(): void
    {
        $far = GrowthPressure::score(20.0, 50.0, 0, 90);
        $near = GrowthPressure::score(20.0, 50.0, 0, 7);
        $expired = GrowthPressure::score(20.0, 50.0, 0, 0);

        $this->assertGreaterThan($far, $near);
        $this->assertGreaterThanOrEqual($near, $expired);
    }

    public function test_pressure_never_exceeds_one(): void
    {
        // Worst case: full gap, maxed streak, expired window.
        $p = GrowthPressure::score(0.0, 100.0, 99, 0);
        $this->assertNotNull($p);
        $this->assertLessThanOrEqual(1.0, $p);
    }

    public function test_rung_thresholds(): void
    {
        $this->assertSame(GrowthPressure::RUNG_NONE, GrowthPressure::rung(0.0));
        $this->assertSame(GrowthPressure::RUNG_NONE, GrowthPressure::rung(0.09));
        $this->assertSame(GrowthPressure::RUNG_PROMPT, GrowthPressure::rung(0.10));
        $this->assertSame(GrowthPressure::RUNG_MIX, GrowthPressure::rung(0.25));
        $this->assertSame(GrowthPressure::RUNG_SCHEDULE, GrowthPressure::rung(0.50));
        $this->assertSame(GrowthPressure::RUNG_DISTRIBUTION, GrowthPressure::rung(0.75));
        $this->assertSame(GrowthPressure::RUNG_DISTRIBUTION, GrowthPressure::rung(1.0));
    }

    public function test_null_pressure_unlocks_no_actuator(): void
    {
        $this->assertSame(GrowthPressure::RUNG_NONE, GrowthPressure::rung(null));
    }

    public function test_real_prod_instagram_goal_reaches_top_rung(): void
    {
        // The case this whole change exists for. Prod 2026-08-01, brand#1:
        // Instagram followers 7 -> 9 against a 10,000 target, 0% progress,
        // 47.4% of the window elapsed, lagging every week since 2026-06-14,
        // ~42 days left. The old system emitted one unchanging paragraph.
        $pressure = GrowthPressure::score(0.0, 47.4, 6, 42);

        $this->assertNotNull($pressure);
        $this->assertSame(
            GrowthPressure::RUNG_DISTRIBUTION,
            GrowthPressure::rung($pressure),
            'a goal this far behind, this long, this close to its deadline must reach the top rung',
        );
    }

    public function test_objective_mapping_covers_every_goal_metric(): void
    {
        // Every metric an operator can pick must map to an objective, or L2
        // objective-skew silently does nothing for that goal type.
        foreach (\App\Models\BrandGrowthGoal::METRICS as $metric) {
            $this->assertNotNull(
                GrowthPressure::objectiveForMetric($metric),
                "metric '{$metric}' has no objective mapping — L2 skew would be a no-op for it",
            );
        }
    }

    public function test_unknown_metric_maps_to_null(): void
    {
        $this->assertNull(GrowthPressure::objectiveForMetric('vanity_score'));
    }
}
