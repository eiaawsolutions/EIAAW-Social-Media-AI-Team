<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategistRecommendation extends Model
{
    protected $fillable = [
        'brand_id', 'is_current',
        'window_starts_on', 'window_ends_on',
        'pillar_mix', 'format_mix', 'platform_mix',
        'top_performers', 'summary',
        'post_count_in_window', 'impressions_total', 'engagement_total',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'window_starts_on' => 'date',
            'window_ends_on' => 'date',
            'pillar_mix' => 'array',
            'format_mix' => 'array',
            'platform_mix' => 'array',
            'top_performers' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
