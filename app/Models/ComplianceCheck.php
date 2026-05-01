<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCheck extends Model
{
    protected $fillable = [
        'draft_id', 'brand_id', 'check_type', 'score', 'threshold',
        'result', 'reason', 'details', 'model_id', 'latency_ms', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'threshold' => 'decimal:4',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Used in the failure-mode telemetry dashboard. */
    public function scopeFailures($query)
    {
        return $query->where('result', 'fail');
    }
}
