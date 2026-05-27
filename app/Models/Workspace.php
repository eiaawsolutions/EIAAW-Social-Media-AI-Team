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
        'blotato_api_key_handle',
        'blotato_account_email',
        'blotato_connected_at',
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
            'blotato_connected_at' => 'datetime',
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

    /**
     * True iff this workspace has no Blotato account wired yet. UI surfaces
     * a "Connect your Blotato account" empty state in /agency/platforms and
     * publishing jobs short-circuit with a clear failure message instead of
     * leaking HQ's accounts via the legacy fromConfig() path.
     */
    public function needsBlotatoSetup(): bool
    {
        return empty($this->blotato_api_key_handle);
    }

    /**
     * Active (non-archived) brand count. Drives PlanCaps::canAddBrand —
     * archived brands free up the slot so an operator can rotate without
     * an upgrade.
     */
    public function activeBrandsCount(): int
    {
        return $this->brands()->whereNull('archived_at')->count();
    }

    /**
     * Published-post count for the current calendar month in the workspace's
     * timezone. Used by PlanCaps::canPublishMorePosts to gate
     * SubmitScheduledPost. Counts `status='published'` rows whose
     * `published_at` falls in this month. Does NOT count posts deferred to
     * next period (status='queued_next_period') because those rows haven't
     * been billed against Blotato yet.
     */
    public function publishedPostsThisMonth(): int
    {
        $tz = (string) ($this->settings['timezone'] ?? config('app.timezone', 'UTC'));
        $startOfMonth = now($tz)->startOfMonth()->utc();

        return \App\Models\ScheduledPost::query()
            ->whereHas('brand', fn ($q) => $q->where('workspace_id', $this->id))
            ->where('status', 'published')
            ->where('published_at', '>=', $startOfMonth)
            ->count();
    }

    /**
     * AI-video generations for the current calendar month. Counts AiCost
     * rows where agent_role='video' AND provider='fal' for any brand in
     * this workspace. Used by PlanCaps::canGenerateMoreAiVideos to gate
     * VideoAgent before the FAL call (cost is incurred at generation, so
     * gate before, not after).
     */
    public function aiVideosThisMonth(): int
    {
        $tz = (string) ($this->settings['timezone'] ?? config('app.timezone', 'UTC'));
        $startOfMonth = now($tz)->startOfMonth()->utc();

        return AiCost::query()
            ->where('workspace_id', $this->id)
            ->where('agent_role', 'video')
            ->where('provider', 'fal')
            ->where('called_at', '>=', $startOfMonth)
            ->count();
    }

    /**
     * True iff the workspace has both provisioned a Blotato handle AND we've
     * successfully pinged it at least once. Drives the green/grey "Connected"
     * pill in the platforms page and the operator-side health dashboard.
     */
    public function hasBlotatoConnected(): bool
    {
        return ! empty($this->blotato_api_key_handle)
            && $this->blotato_connected_at !== null;
    }
}
