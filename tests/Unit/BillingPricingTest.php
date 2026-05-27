<?php

namespace Tests\Unit;

use App\Services\StripePriceCache;
use Tests\TestCase;

/**
 * Annual pricing + tax-readiness contracts.
 *
 * The actual Stripe API calls inside StripePriceCache::getOrCreate are
 * exercised in integration paths, not here. These tests lock the pure
 * math (annual = monthly × multiplier) and the config invariants
 * (tax_behavior=exclusive, tax not active by default) so a future code
 * change that breaks the contract surfaces immediately.
 */
class BillingPricingTest extends TestCase
{
    public function test_monthly_unit_amount_is_price_myr_times_100(): void
    {
        $planConfig = ['price_myr' => 549, 'name' => 'Solo'];
        $this->assertSame(54_900, StripePriceCache::unitAmountFor($planConfig, 'month'));
    }

    public function test_annual_unit_amount_uses_annual_multiplier(): void
    {
        // Default multiplier in config is 10. RM 549 × 10 = RM 5,490 = 549,000 cents.
        $planConfig = ['price_myr' => 549, 'name' => 'Solo'];
        $this->assertSame(549_000, StripePriceCache::unitAmountFor($planConfig, 'year'));
    }

    public function test_annual_multiplier_is_10_by_default(): void
    {
        // Locks the marketing copy promise. If anyone changes the multiplier,
        // this test breaks and forces a deliberate decision.
        $this->assertSame(10, (int) config('billing.annual_multiplier'));
    }

    public function test_annual_savings_equal_two_months_of_monthly(): void
    {
        // "2 months free" = 12 monthly - 10× annual rate.
        // Solo: 549 × 12 = 6588; 549 × 10 = 5490; saves 1098.
        $planConfig = ['price_myr' => 549];
        $this->assertSame(5_490, StripePriceCache::annualMyr($planConfig));
        $this->assertSame(1_098, StripePriceCache::annualSavingsMyr($planConfig));
    }

    public function test_annual_savings_for_all_real_tiers(): void
    {
        foreach (['solo', 'studio', 'agency'] as $plan) {
            $cfg = config('billing.plans.' . $plan);
            $monthlyTwelve = ($cfg['price_myr'] ?? 0) * 12;
            $annual = StripePriceCache::annualMyr($cfg);
            $savings = StripePriceCache::annualSavingsMyr($cfg);

            $this->assertSame($monthlyTwelve - $annual, $savings, "savings math wrong for {$plan}");
            // Exactly 2 months of savings (rounding tolerance: integer match).
            $this->assertSame((int) ($cfg['price_myr'] * 2), $savings,
                "{$plan} annual should save exactly 2 months");
        }
    }

    public function test_tax_is_not_active_by_default(): void
    {
        // SST stays OFF until EIAAW crosses RM 500k MY revenue + registers
        // with RMCD. Test locks that default — if anyone flips it on
        // without going through that real-world process, this fails.
        $this->assertFalse(
            (bool) config('billing.tax.enabled'),
            'SST should NOT be active by default — only enabled after RMCD registration crossing the RM 500k threshold.'
        );
    }

    public function test_tax_behavior_is_exclusive(): void
    {
        // Critical contract: Stripe Prices are created with
        // tax_behavior=exclusive so the marketed price (RM 549) matches
        // the charged price for any non-SST customer. When SST flips on,
        // Stripe adds 8% on top without needing to recreate every Price.
        $this->assertSame('exclusive', (string) config('billing.tax.price_tax_behavior'));
    }

    public function test_sst_rate_is_8_percent(): void
    {
        // Verified rate: Malaysia increased Service Tax from 6% to 8% on
        // 1 March 2024. Original config draft said 6% — this test catches
        // anyone reintroducing the outdated number.
        $this->assertEqualsWithDelta(0.08, (float) config('billing.tax.rate'), 0.0001);
    }

    public function test_allowed_countries_is_malaysia_only_in_v1(): void
    {
        $allowed = (array) config('billing.allowed_countries');
        $this->assertSame(['MY'], $allowed,
            'v1 ships Malaysia-only to keep SST + currency simple. Adding countries opens compliance surface (EU OSS, UK VAT, SG GST).');
    }

    public function test_solo_tier_uses_the_new_pricing(): void
    {
        // Specific number-locking test. If you change config/billing.php
        // prices, update THIS test deliberately — that's the point.
        $this->assertSame(549, (int) config('billing.plans.solo.price_myr'));
        $this->assertSame(1099, (int) config('billing.plans.studio.price_myr'));
        $this->assertSame(3499, (int) config('billing.plans.agency.price_myr'));
    }
}
