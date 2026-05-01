<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class BrandCorpusItem extends Model
{
    use HasNeighbors;

    protected $table = 'brand_corpus';

    protected $fillable = [
        'brand_id', 'source_type', 'source_url', 'source_label',
        'source_published_at', 'content', 'metrics', 'platform_meta', 'embedding',
    ];

    protected function casts(): array
    {
        return [
            'source_published_at' => 'datetime',
            'metrics' => 'array',
            'platform_meta' => 'array',
            'embedding' => Vector::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Used by Compliance to compute the dedup score against prior posts. */
    public function scopeForBrandHistorical($query, int $brandId)
    {
        return $query->where('brand_id', $brandId)->where('source_type', 'historical_post');
    }
}
