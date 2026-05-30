<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMetric extends Model
{
    protected $fillable = [
        'scheduled_post_id', 'brand_id', 'platform',
        'observed_at', 'source',
        'blotato_published_id', 'blotato_last_fetched_at',
        'impressions', 'reach', 'likes', 'comments', 'shares', 'saves',
        'video_views', 'profile_visits', 'url_clicks',
        'engagement_rate', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'blotato_last_fetched_at' => 'datetime',
            'engagement_rate' => 'decimal:4',
            'raw_payload' => 'array',
        ];
    }

    /**
     * Did this snapshot carry any real engagement reading? A dormant
     * "we tried, Blotato had nothing yet" row has every counter NULL.
     * Used by the dashboard/readiness checks to tell "no data captured"
     * from "captured zero" — Truthfulness Contract.
     */
    public function hasReading(): bool
    {
        return $this->impressions !== null
            || $this->reach !== null
            || $this->likes !== null
            || $this->comments !== null
            || $this->shares !== null
            || $this->saves !== null
            || $this->video_views !== null
            || $this->profile_visits !== null
            || $this->url_clicks !== null;
    }

    public function scheduledPost(): BelongsTo
    {
        return $this->belongsTo(ScheduledPost::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
