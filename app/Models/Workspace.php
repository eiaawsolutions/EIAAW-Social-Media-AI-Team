<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Workspace extends Model
{
    use Billable, HasFactory;

    /**
     * Cashier reads/writes `stripe_id` on the billable model. Our column is
     * `stripe_customer_id` for clarity (we don't want to confuse it with
     * users.stripe_id elsewhere in the EIAAW stack). Proxy the attribute
     * via a mutator + accessor so Cashier's read AND write paths work
     * without us renaming the column.
     */
    public function getStripeIdAttribute(): ?string
    {
        return $this->attributes['stripe_customer_id'] ?? null;
    }

    public function setStripeIdAttribute(?string $value): void
    {
        $this->attributes['stripe_customer_id'] = $value;
    }

    protected $fillable = [
        'slug',
        'name',
        'owner_id',
        'type',
        'plan',
        'subscription_status',
        'trial_ends_at',
        'past_due_at',
        'canceled_at',
        'stripe_customer_id',
        'pm_type',
        'pm_last_four',
        'billplz_collection_id',
        'logo_url',
        'settings',
        'suspended_at',
        'suspended_reason',
        'publishing_paused',
        'publishing_paused_at',
        'publishing_paused_reason',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'past_due_at' => 'datetime',
            'canceled_at' => 'datetime',
            'suspended_at' => 'datetime',
            'publishing_paused' => 'boolean',
            'publishing_paused_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function aiCosts(): HasMany
    {
        return $this->hasMany(AiCost::class);
    }

    public function auditLog(): HasMany
    {
        return $this->hasMany(AuditLogEntry::class);
    }

    /** Workspaces are flat-priced per tier — no per-user / per-channel multipliers. */
    protected function isInternal(): Attribute
    {
        return Attribute::get(fn () => $this->type === 'internal');
    }

    protected function isSuspended(): Attribute
    {
        return Attribute::get(fn () => $this->suspended_at !== null);
    }

    /**
     * True for workspaces that have entitlement to the panel right now —
     * either a live trial or an active subscription. Drives the trial-expiry
     * guard middleware. EIAAW internal workspaces always pass.
     */
    public function hasActiveAccess(): bool
    {
        if ($this->plan === 'eiaaw_internal') {
            return true;
        }

        if ($this->isSuspended) {
            return false;
        }

        return match ($this->subscription_status) {
            'active' => true,
            'trialing' => $this->trial_ends_at !== null && $this->trial_ends_at->isFuture(),
            'past_due' => $this->past_due_at !== null && $this->past_due_at->copy()->addDays(3)->isFuture(),
            default => false,
        };
    }

    public function trialEnded(): bool
    {
        return $this->subscription_status === 'trialing'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }
}
