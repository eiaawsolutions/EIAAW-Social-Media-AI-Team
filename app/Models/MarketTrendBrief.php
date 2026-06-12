<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The synthesised market & trend brief. One is_current=true row per brand;
 * written by MarketIntelAgent, read by StrategistAgent. Twin of
 * StrategistRecommendation / CompetitorStrategyBrief.
 */
class MarketTrendBrief extends Model
{
    protected $fillable = [
        'brand_id', 'workspace_id', 'is_current',
        'window_starts_on', 'window_ends_on',
        'market_summary', 'trends', 'seasonal_moments',
        'verified_signal_count', 'summary',
        'model_id', 'prompt_version', 'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'window_starts_on' => 'date',
            'window_ends_on' => 'date',
            'trends' => 'array',
            'seasonal_moments' => 'array',
            'cost_usd' => 'float',
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

    /** The freshest current brief for a brand, or null. */
    public function scopeCurrentForBrand(Builder $q, int $brandId): Builder
    {
        return $q->where('brand_id', $brandId)->where('is_current', true)->latest();
    }
}
