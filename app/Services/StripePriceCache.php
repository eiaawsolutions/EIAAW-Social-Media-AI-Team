<?php

namespace App\Services;

use App\Models\StripePrice;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use RuntimeException;

/**
 * Lazy-creates Stripe Products + Prices on first checkout per plan and caches
 * the resulting Price ID in the stripe_prices table. Replaces the old
 * STRIPE_PRICE_*_MYR_MONTHLY|ANNUAL env vars.
 *
 * Plan catalog lives in config/billing.php. To add a new plan, add it to that
 * config — no env changes required, the first checkout creates the Stripe
 * objects on demand.
 *
 * Concurrency: getOrCreate() takes a row-level lock so two simultaneous
 * first-checkouts for the same plan can't both POST to Stripe.
 */
class StripePriceCache
{
    /**
     * Return the Stripe Price ID for a plan+interval+currency. Creates the
     * Stripe Product and Price on first call, then reads from cache.
     *
     * @throws RuntimeException when the plan is not in config/billing.php
     */
    public function getOrCreate(string $plan, string $interval = 'month', ?string $currency = null): string
    {
        $currency = $currency ?? config('billing.currency', 'myr');
        $plans = config('billing.plans', []);

        if (! isset($plans[$plan])) {
            throw new RuntimeException("Unknown plan '{$plan}'. Add it to config/billing.php.");
        }

        $planConfig = $plans[$plan];

        // Fast path: cached row exists
        $cached = StripePrice::where('plan', $plan)
            ->where('interval', $interval)
            ->where('currency', $currency)
            ->first();

        if ($cached) {
            return $cached->price_id;
        }

        // Slow path: create Stripe Product + Price under a row-locked transaction
        // to prevent duplicate Stripe objects when two checkouts race for the
        // same uncached plan.
        return DB::transaction(function () use ($plan, $interval, $currency, $planConfig) {
            // Re-check inside the lock — another request may have just created it.
            $stillNone = StripePrice::where('plan', $plan)
                ->where('interval', $interval)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($stillNone) {
                return $stillNone->price_id;
            }

            $stripe = Cashier::stripe();
            $unitAmount = $this->unitAmountFor($planConfig, $interval);

            // One Product per plan is reused across intervals — so monthly
            // and annual Prices share the same Product (cleaner Stripe
            // dashboard, cleaner reporting). Look for an existing Product
            // via a different-interval cached row before creating new.
            $existingProductId = StripePrice::where('plan', $plan)
                ->where('currency', $currency)
                ->value('product_id');

            if ($existingProductId) {
                $productId = $existingProductId;
            } else {
                $product = $stripe->products->create([
                    'name'        => 'EIAAW Social Media Team — '.$planConfig['name'],
                    'description' => $planConfig['features'] ?? null,
                    'metadata'    => ['plan' => $plan],
                ]);
                $productId = $product->id;
            }

            // tax_behavior=exclusive means: the unit_amount above is the
            // PRE-TAX price (RM 549 etc). When SST is later enabled and the
            // customer is in Malaysia, Stripe adds 8% on top at invoice
            // time. This keeps the marketed price identical to the charged
            // price for any non-SST customer (= all of them today) and
            // lets us flip the SST switch without re-creating Prices.
            // See config/billing.php → 'tax' for the rationale.
            $taxBehavior = (string) config('billing.tax.price_tax_behavior', 'exclusive');

            $price = $stripe->prices->create([
                'product'      => $productId,
                'unit_amount'  => $unitAmount,
                'currency'     => strtolower($currency),
                'recurring'    => ['interval' => $interval],
                'tax_behavior' => $taxBehavior,
                'metadata'     => [
                    'plan' => $plan,
                    'interval' => $interval,
                    // Annual prices are MONTHLY × annual_multiplier
                    // (default 10). Recorded so we can audit savings.
                    'is_annual' => $interval === 'year' ? '1' : '0',
                    'annual_multiplier' => (string) (int) config('billing.annual_multiplier', 10),
                ],
            ]);

            $cached = StripePrice::create([
                'plan'        => $plan,
                'interval'    => $interval,
                'currency'    => strtolower($currency),
                'product_id'  => $productId,
                'price_id'    => $price->id,
                'unit_amount' => $unitAmount,
            ]);

            return $cached->price_id;
        });
    }

    /**
     * Compute the Stripe unit_amount (in cents) for a plan + interval.
     *
     * Monthly = price_myr × 100.
     * Annual  = price_myr × 100 × annual_multiplier (default 10 = "12 for 10").
     *
     * Pure function. Public-static so the migration command and tests can
     * compute the same number without instantiating the cache.
     */
    public static function unitAmountFor(array $planConfig, string $interval): int
    {
        $monthlyCents = (int) round((float) ($planConfig['price_myr'] ?? 0) * 100);
        if ($interval === 'year') {
            $multiplier = (int) config('billing.annual_multiplier', 10);
            return $monthlyCents * $multiplier;
        }
        return $monthlyCents;
    }

    /**
     * Human-readable annual price in major units (MYR), used by the Billing
     * page to show the dual "RM 549/mo or RM 5,490/yr (save RM 1,098)" copy.
     */
    public static function annualMyr(array $planConfig): int
    {
        return (int) round(self::unitAmountFor($planConfig, 'year') / 100);
    }

    /**
     * Annual savings vs paying 12 months monthly. The "2 months free" amount.
     */
    public static function annualSavingsMyr(array $planConfig): int
    {
        $twelveMonthsAtMonthly = (int) (($planConfig['price_myr'] ?? 0) * 12);
        return max(0, $twelveMonthsAtMonthly - self::annualMyr($planConfig));
    }
}
