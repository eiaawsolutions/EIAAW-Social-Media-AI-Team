<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitorAd extends Model
{
    protected $fillable = [
        'brand_id', 'workspace_id',
        'platform', 'competitor_handle', 'competitor_label',
        'source_ad_id', 'source_url', 'dedup_hash',
        'body', 'asset_urls', 'cta', 'landing_url', 'targeting',
        'platforms_seen_on',
        'first_seen_at', 'last_seen_at', 'observed_at', 'expires_at',
        'pipeline_run_id',
    ];

    protected function casts(): array
    {
        return [
            'asset_urls' => 'array',
            'targeting' => 'array',
            'platforms_seen_on' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    /** Live = within rolling window, observed at least once recently. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }
}
