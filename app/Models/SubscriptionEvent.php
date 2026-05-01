<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SubscriptionEvent — Stripe webhook idempotency log.
 * See database/migrations/2026_05_01_120100_create_subscription_events_table.php
 * for the why; mirrors the employee-portal pattern.
 */
class SubscriptionEvent extends Model
{
    protected $fillable = [
        'workspace_id',
        'stripe_event_id',
        'event_type',
        'payload',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
