<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * State-machine row for multi-step agent workflows. Replaces Inngest.
 *
 * Workers tail this table for pending runs:
 *   PipelineRun::where('state', 'queued')->where('next_run_at', '<=', now())->lockForUpdate()->first()
 *
 * Each agent worker advances the run through its workflow's step sequence,
 * persisting state_data between steps. Failures retry up to max_attempts with
 * exponential backoff via next_run_at.
 */
class PipelineRun extends Model
{
    protected $fillable = [
        'workflow', 'brand_id', 'workspace_id',
        'subject_type', 'subject_id',
        'input', 'state_data', 'current_step',
        'state', 'attempt', 'max_attempts',
        'next_run_at', 'started_at', 'completed_at',
        'last_error', 'error_history', 'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'state_data' => 'array',
            'error_history' => 'array',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, ['completed', 'failed', 'cancelled']);
    }

    /** Calculate next retry time with exponential backoff: 30s, 2min, 5min. */
    public function nextRetryAt(): \Carbon\Carbon
    {
        $delays = [30, 120, 300];
        $idx = min($this->attempt, count($delays) - 1);
        return now()->addSeconds($delays[$idx]);
    }
}
