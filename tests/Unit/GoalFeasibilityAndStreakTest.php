<?php

namespace Tests\Unit;

use App\Models\BrandGrowthGoal;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure tests for goal feasibility (step 5) and streak advance (step 4).
 *
 * Feasibility is the Truthfulness Contract applied to targets: a goal that no
 * content plan can close must be labelled as such at creation, not reported as
 * merely "lagging" every week for 90 days.
 */
class GoalFeasibilityAndStreakTest extends TestCase
{
    private function window(int $days = 90): array
    {
        $start = Carbon::parse('2026-06-14');

        return [$start, $start->copy()->addDays($days)];
    }

    public function test_required_per_day_is_span_over_days(): void
    {
        [$start, $end] = $this->window(90);
        $f = BrandGrowthGoal::feasibility(100, 1000, $start, $end, null);

        $this->assertEqualsWithDelta(10.0, $f['required_per_day'], 0.01);
    }

    public function test_no_measured_rate_is_unknown_not_a_guess(): void
    {
        [$start, $end] = $this->window();
        $f = BrandGrowthGoal::feasibility(0, 5000, $start, $end, null);

        $this->assertSame(BrandGrowthGoal::FEASIBILITY_UNKNOWN, $f['verdict']);
        $this->assertNull($f['multiple']);
        $this->assertNotNull($f['required_per_day'], 'the arithmetic floor is still knowable');
    }

    public function test_close_to_measured_rate_is_plausible(): void
    {
        [$start, $end] = $this->window(100);
        // needs 10/day, brand runs at 9/day
        $f = BrandGrowthGoal::feasibility(0, 1000, $start, $end, 9.0);

        $this->assertSame(BrandGrowthGoal::FEASIBILITY_PLAUSIBLE, $f['verdict']);
    }

    public function test_a_few_times_measured_rate_is_a_stretch(): void
    {
        [$start, $end] = $this->window(100);
        // needs 10/day, brand runs at 3/day => 3.3x
        $f = BrandGrowthGoal::feasibility(0, 1000, $start, $end, 3.0);

        $this->assertSame(BrandGrowthGoal::FEASIBILITY_STRETCH, $f['verdict']);
    }

    public function test_the_real_prod_threads_goal_is_infeasible(): void
    {
        // Prod goal#2: Threads followers 1 -> 10,000 in 90 days, on an account
        // measured at 0 net new followers over 30 days. Reported weekly as
        // "lagging" since 2026-06-14 as though it were ordinary underperformance.
        [$start, $end] = $this->window(90);
        $f = BrandGrowthGoal::feasibility(1, 10000, $start, $end, 0.0);

        $this->assertSame(BrandGrowthGoal::FEASIBILITY_INFEASIBLE, $f['verdict']);
        $this->assertNull($f['multiple'], 'no multiple is quotable against a zero rate');
        $this->assertGreaterThan(100, $f['required_per_day']);
    }

    public function test_negative_measured_rate_is_infeasible(): void
    {
        [$start, $end] = $this->window();
        $f = BrandGrowthGoal::feasibility(500, 5000, $start, $end, -3.0);

        $this->assertSame(BrandGrowthGoal::FEASIBILITY_INFEASIBLE, $f['verdict']);
    }

    public function test_degenerate_goal_is_unknown(): void
    {
        [$start, $end] = $this->window();

        // target at or below baseline
        $this->assertSame(
            BrandGrowthGoal::FEASIBILITY_UNKNOWN,
            BrandGrowthGoal::feasibility(5000, 5000, $start, $end, 10.0)['verdict'],
        );
        // zero-length window
        $this->assertSame(
            BrandGrowthGoal::FEASIBILITY_UNKNOWN,
            BrandGrowthGoal::feasibility(0, 5000, $start, $start, 10.0)['verdict'],
        );
    }

    // ── streak ──────────────────────────────────────────────────────────

    public function test_lagging_advances_the_streak(): void
    {
        $this->assertSame(1, BrandGrowthGoal::nextStreak(0, 'lagging'));
        $this->assertSame(7, BrandGrowthGoal::nextStreak(6, 'lagging'));
    }

    public function test_recovery_resets_the_streak(): void
    {
        $this->assertSame(0, BrandGrowthGoal::nextStreak(6, 'on_track'));
        $this->assertSame(0, BrandGrowthGoal::nextStreak(6, 'ahead'));
    }

    public function test_no_reading_holds_the_streak_rather_than_resetting_it(): void
    {
        // An unmeasurable week is not evidence the goal recovered, and not
        // evidence it got worse. Resetting would quietly erase escalation
        // history every time metrics collection had a bad week.
        $this->assertSame(6, BrandGrowthGoal::nextStreak(6, null));
    }

    public function test_streak_never_goes_negative(): void
    {
        $this->assertSame(0, BrandGrowthGoal::nextStreak(-5, 'on_track'));
        $this->assertSame(1, BrandGrowthGoal::nextStreak(-5, 'lagging'));
    }

    public function test_metric_classification_is_total_and_disjoint(): void
    {
        // Every operator-selectable metric must be classified exactly once, or
        // currentValueFor silently returns null for it — the original bug.
        $classified = array_merge(
            BrandGrowthGoal::STOCK_METRICS,
            BrandGrowthGoal::FLOW_METRICS,
            BrandGrowthGoal::RATIO_METRICS,
        );

        sort($classified);
        $all = BrandGrowthGoal::METRICS;
        sort($all);

        $this->assertSame($all, $classified, 'every goal metric must be classified exactly once');
    }
}
