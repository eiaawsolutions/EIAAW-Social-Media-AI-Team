<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'owner_id',
        'type',
        'plan',
        'trial_ends_at',
        'stripe_customer_id',
        'billplz_collection_id',
        'logo_url',
        'settings',
        'suspended_at',
        'suspended_reason',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
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
}
