<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stripe_prices — durable cache of Stripe Product + Price IDs lazily created
 * on first checkout per plan. Replaces the old STRIPE_PRICE_*_MYR_* env vars.
 *
 * One row per (plan, interval, currency). StripePriceCache::getOrCreate()
 * uses lockForUpdate() to prevent two concurrent first-checkouts creating
 * duplicate Stripe Prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_prices', function (Blueprint $table) {
            $table->id();
            $table->string('plan');           // 'solo' | 'studio' | 'agency'
            $table->string('interval', 16);   // 'month' | 'year'
            $table->string('currency', 3);    // 'myr'
            $table->string('product_id');     // prod_...
            $table->string('price_id');       // price_...
            $table->unsignedInteger('unit_amount'); // in cents (smallest currency unit)
            $table->timestamps();

            $table->unique(['plan', 'interval', 'currency']);
            $table->index('price_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_prices');
    }
};
