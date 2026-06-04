<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * StripeSyncPrices — DEPRECATED / DISABLED (2026-06-04).
 *
 * This command pre-dates the lazy-creation model. EIAAW SMT no longer
 * pre-creates Stripe Products/Prices or stores STRIPE_PRICE_* env vars: prices
 * are LAZY-CREATED on first checkout per plan by App\Services\StripePriceCache,
 * reading the SINGLE source of truth in config/billing.php (and cached in the
 * stripe_prices table). See the header of config/billing.php.
 *
 * It is disabled rather than deleted because its name is easy to reach for, and
 * a half-remembered `stripe:sync-prices --apply` would have created Stripe
 * objects from this file's OWN hardcoded tier list (RM 99/299/799 — two pricing
 * eras stale) — a real footgun against a live account. handle() now refuses and
 * points at the correct mechanism. The old per-tier constants below are removed
 * so there is no stale price/cap data anywhere outside config/billing.php.
 *
 * If a future need to pre-provision Stripe objects ever returns, rebuild this on
 * top of StripePriceCache + config/billing.php — never reintroduce a private
 * price table here.
 */
class StripeSyncPrices extends Command
{
    protected $signature = 'stripe:sync-prices';

    protected $description = '[DISABLED] Superseded by StripePriceCache lazy-creation from config/billing.php.';

    public function handle(): int
    {
        $this->error('stripe:sync-prices is DISABLED.');
        $this->newLine();
        $this->line('EIAAW SMT no longer pre-creates Stripe Products/Prices or uses STRIPE_PRICE_* env vars.');
        $this->line('Prices are lazy-created on first checkout per plan by App\Services\StripePriceCache,');
        $this->line('reading the single source of truth in config/billing.php (cached in the stripe_prices table).');
        $this->newLine();
        $this->line('To change a price: edit config/billing.php and let the next checkout create the new Stripe Price.');
        $this->line('Existing subscribers keep their locked price_id; only new signups get the new number.');
        $this->line('For repricing existing catalog entries, see: php artisan billing:migrate-existing-prices');

        return self::FAILURE;
    }
}
