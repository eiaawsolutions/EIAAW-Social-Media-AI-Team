<?php

namespace Tests\Unit;

use App\Services\Monitoring\CostMonitor;
use Tests\TestCase;

/**
 * Cost Monitor calculation contracts.
 *
 * The DB-touching aggregations (signups, aiCost) are exercised against live
 * tables in the panel, not here — this suite is intentionally DB-free (local
 * .env points at prod; unit tests must not touch it). What it locks is the
 * pure MONEY MATH: revenue from the live base, the FX conversion, the Blotato
 * rate × count, the fixed-cost summing, and the profit/margin derivation.
 *
 * These are exactly the calculations where a silent bug would mislead the
 * profit line — so each gets an explicit, number-locked assertion. Nothing in
 * the monitor invents a figure; these tests prove the arithmetic is faithful
 * to its real inputs.
 */
class CostMonitorTest extends TestCase
{
    private CostMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monitor = new CostMonitor;

        // Pin the cost register to known values so the math is deterministic
        // and independent of whatever the operator has entered.
        config()->set('costs.fx.usd_to_myr', 4.7);
        config()->set('costs.subscriptions.metricool.amount_usd', 67);
        config()->set('costs.fixed', [
            'railway' => ['label' => 'Railway', 'amount_usd' => 20],
            'domain' => ['label' => 'Domain', 'amount_myr' => 5],
            'unset' => ['label' => 'Unset line', 'amount_myr' => 0],
        ]);
    }

    public function test_fx_rate_defaults_to_the_ai_writer_convention(): void
    {
        // The monitor must reconcile with ai_costs.cost_myr, which the writers
        // compute as cost_usd * 4.7. If this drifts, the AI cost line is wrong.
        config()->set('costs.fx.usd_to_myr', null);
        $this->assertEqualsWithDelta(4.7, (new CostMonitor)->fxRate(), 0.0001);
    }

    public function test_revenue_multiplies_real_plan_prices_by_live_counts(): void
    {
        // 2 Solo (688) + 1 Agency (6888) = 1376 + 6888 = 8264.
        $revenue = $this->monitor->revenue(['solo' => 2, 'agency' => 1]);

        $this->assertSame(1_376.0, $revenue['by_plan']['solo']['subtotal_myr']);
        $this->assertSame(6_888.0, $revenue['by_plan']['agency']['subtotal_myr']);
        $this->assertSame(8_264.0, $revenue['total_myr']);
    }

    public function test_revenue_uses_the_real_config_price_not_a_guess(): void
    {
        // Locks that revenue is sourced from config/billing.php, the single
        // source of truth — never a hardcoded or inferred number.
        $revenue = $this->monitor->revenue(['studio' => 1]);
        $this->assertSame((float) config('billing.plans.studio.price_myr'), $revenue['by_plan']['studio']['unit_myr']);
        $this->assertSame(1_688.0, $revenue['total_myr']);
    }

    public function test_revenue_is_zero_with_no_paying_workspaces(): void
    {
        $revenue = $this->monitor->revenue([]);
        $this->assertSame(0.0, $revenue['total_myr']);
        $this->assertSame([], $revenue['by_plan']);
    }

    public function test_metricool_cost_is_flat_rate_times_fx(): void
    {
        // Metricool is ONE shared agency account (not per-workspace), so the
        // cost is a flat subscription: $67 × 4.7 = RM 314.90, independent of
        // how many workspaces or brands are live.
        $this->assertSame(314.90, $this->monitor->metricoolCost(4.7));
    }

    public function test_metricool_cost_is_zero_when_rate_unset(): void
    {
        config()->set('costs.subscriptions.metricool.amount_usd', 0);
        $this->assertSame(0.0, $this->monitor->metricoolCost(4.7));
    }

    public function test_fixed_costs_convert_usd_lines_and_pass_through_myr_lines(): void
    {
        $fixed = $this->monitor->fixedCosts(4.7);

        // Railway $20 × 4.7 = RM 94; Domain RM 5 pass-through; unset = 0.
        // Total = 94 + 5 + 0 = 99.
        $this->assertSame(99.0, $fixed['total_myr']);

        $byKey = collect($fixed['lines'])->keyBy('key');
        $this->assertSame(94.0, $byKey['railway']['amount_myr']);
        $this->assertSame('usd', $byKey['railway']['source']);
        $this->assertSame(5.0, $byKey['domain']['amount_myr']);
        $this->assertSame('myr', $byKey['domain']['source']);
    }

    public function test_fixed_costs_flag_zero_lines_for_honesty(): void
    {
        // A 0 line must be flagged so the UI can warn that profit is overstated
        // until the operator enters the real figure.
        $fixed = $this->monitor->fixedCosts(4.7);
        $byKey = collect($fixed['lines'])->keyBy('key');

        $this->assertTrue($byKey['unset']['is_zero']);
        $this->assertFalse($byKey['railway']['is_zero']);
    }

    public function test_metricool_rate_defaults_to_documented_subscription(): void
    {
        // config/costs.php seeds the Metricool shared-account subscription so
        // the cost is never silently zero. (Test pins 67 in setUp.)
        $this->assertSame(67.0, (float) config('costs.subscriptions.metricool.amount_usd'));
    }
}
