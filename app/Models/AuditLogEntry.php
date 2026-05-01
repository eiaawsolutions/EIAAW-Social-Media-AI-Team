<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Append-only audit log. UPDATE/DELETE blocked by Postgres trigger.
 *
 * Always insert via Audit::log(...) helper (not yet wired) which captures
 * actor, before/after, and request context automatically.
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    protected $fillable = [
        'workspace_id', 'brand_id', 'actor_user_id', 'actor_type',
        'action', 'subject_type', 'subject_id',
        'before', 'after', 'context', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Hard-block app-layer attempts to mutate audit rows. The DB trigger is the second wall. */
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('audit_log entries are immutable');
    }

    public function delete()
    {
        throw new RuntimeException('audit_log entries are immutable');
    }
}
