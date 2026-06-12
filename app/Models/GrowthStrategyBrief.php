<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The synthesised growth strategy brief. One is_current=true row per brand;
 * written by GrowthStrategistAgent, read by StrategistAgent (calendar-build),
 * WriterAgent (per-objective guidance), the auto-scheduler (best times), and the
 * dashboard card. Twin of CompetitorStrategyBrief / MarketTrendBrief.
 */
class GrowthStrategyBrief extends Model
{
    protected $fillable = [
        'brand_id', 'workspace_id', 'is_current',
        'window_starts_on', 'window_ends_on',
        'best_posting_times', 'platform_focus', 'hook_performance',
        'cta_lift', 'follower_velocity', 'recommended_objective_mix', 'goal_progress',
        'objective_guidance',
        'rationale', 'summary',
        'post_count_in_window', 'model_id', 'prompt_version', 'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'window_starts_on' => 'date',
            'window_ends_on' => 'date',
            'best_posting_times' => 'array',
            'platform_focus' => 'array',
            'hook_performance' => 'array',
            'cta_lift' => 'array',
            'follower_velocity' => 'array',
            'recommended_objective_mix' => 'array',
            'goal_progress' => 'array',
            'objective_guidance' => 'array',
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
