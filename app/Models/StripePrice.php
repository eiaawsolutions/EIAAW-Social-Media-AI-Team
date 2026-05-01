<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cached Stripe Product/Price for a plan+interval+currency tuple.
 * Populated on first checkout via App\Services\StripePriceCache.
 */
class StripePrice extends Model
{
    protected $fillable = [
        'plan',
        'interval',
        'currency',
        'product_id',
        'price_id',
        'unit_amount',
    ];

    protected $casts = [
        'unit_amount' => 'integer',
    ];
}
