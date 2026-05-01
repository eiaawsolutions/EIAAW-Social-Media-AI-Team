<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPost extends Model
{
    protected $fillable = [
        'draft_id', 'brand_id', 'platform_connection_id',
        'scheduled_for', 'status', 'blotato_post_id',
        'platform_post_id', 'platform_post_url', 'last_error',
        'attempt_count', 'submitted_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
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

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->attempt_count < 3;
    }
}
