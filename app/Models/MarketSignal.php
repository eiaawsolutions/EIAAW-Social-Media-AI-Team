<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single verified market/trend signal (one search result that passed the
 * MarketSignalNormalizer gate). Rolling-window; twin of CompetitorAd.
 */
class MarketSignal extends Model
{
    protected $fillable = [
        'brand_id', 'workspace_id',
        'signal_class', 'query',
        'title', 'snippet', 'source_url', 'published_at', 'fetched_at',
        'dedup_hash', 'observed_at', 'expires_at',
        'pipeline_run_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'observed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    /** Live = within the rolling window. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }
}
