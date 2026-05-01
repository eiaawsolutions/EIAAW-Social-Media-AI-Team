<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
        'invited_at',
        'accepted_at',
        'invitation_token',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function canApproveAmberLane(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'editor', 'reviewer']);
    }

    public function canApproveRedLane(): bool
    {
        return in_array($this->role, ['owner', 'admin', 'reviewer']);
    }
}
