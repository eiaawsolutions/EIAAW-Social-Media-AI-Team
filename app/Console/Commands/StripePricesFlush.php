<?php

namespace App\Console\Commands;

use App\Models\StripePrice;
use Illuminate\Console\Command;

/**
 * StripePricesFlush — wipe the stripe_prices cache.
 *
 * Needed when rotating between Stripe test/live mode: cached price_* IDs
 * from the previous mode don't exist in the new mode's account, so the
 * first checkout per plan would fail with "No such price" until the cache
 * is cleared. After flush, StripePriceCache::getOrCreate() lazy-creates
 * fresh Products and Prices on the next checkout.
 *
 * Run after rotating STRIPE_SECRET (test→live or live→test). One-shot.
 *
 * Usage:
 *   railway run --service app -- php artisan stripe:prices:flush --apply
 */
class StripePricesFlush extends Command
{
    protected $signature = 'stripe:prices:flush
        {--apply : Actually delete the rows (default is dry-run)}';

    protected $description = 'Flush the stripe_prices cache after rotating Stripe test/live mode.';

    public function handle(): int
    {
        $rows = StripePrice::all();
        $this->info('Current stripe_prices rows: ' . $rows->count());

        if ($rows->isEmpty()) {
            $this->comment('Nothing to flush.');
            return self::SUCCESS;
        }

        $this->table(
            ['plan', 'interval', 'currency', 'product_id', 'price_id'],
            $rows->map(fn ($r) => [$r->plan, $r->interval, $r->currency, $r->product_id, $r->price_id])->all(),
        );

        if (! $this->option('apply')) {
            $this->comment('Dry-run only. Pass --apply to delete.');
            return self::SUCCESS;
        }

        $deleted = StripePrice::query()->delete();
        $this->info("Deleted {$deleted} row(s). Next checkout per plan will lazy-create fresh Stripe objects.");

        return self::SUCCESS;
    }
}
