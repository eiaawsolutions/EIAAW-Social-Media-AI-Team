<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMetric extends Model
{
    protected $fillable = [
        'scheduled_post_id', 'brand_id', 'platform',
        'observed_at', 'source',
        'impressions', 'reach', 'likes', 'comments', 'shares', 'saves',
        'video_views', 'profile_visits', 'url_clicks',
        'engagement_rate', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'engagement_rate' => 'decimal:4',
            'raw_payload' => 'array',
        ];
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
