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
            $unitAmount = (int) round($planConfig['price_myr'] * 100); // cents

            $product = $stripe->products->create([
                'name'        => 'EIAAW Social Media Team — '.$planConfig['name'],
                'description' => $planConfig['features'] ?? null,
                'metadata'    => ['plan' => $plan],
            ]);

            $price = $stripe->prices->create([
                'product'     => $product->id,
                'unit_amount' => $unitAmount,
                'currency'    => strtolower($currency),
                'recurring'   => ['interval' => $interval],
                'metadata'    => ['plan' => $plan],
            ]);

            $cached = StripePrice::create([
                'plan'        => $plan,
                'interval'    => $interval,
                'currency'    => strtolower($currency),
                'product_id'  => $product->id,
                'price_id'    => $price->id,
                'unit_amount' => $unitAmount,
            ]);

            return $cached->price_id;
        });
    }
}
