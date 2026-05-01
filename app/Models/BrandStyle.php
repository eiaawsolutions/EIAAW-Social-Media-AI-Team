<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class BrandStyle extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'brand_id', 'version', 'is_current', 'content_md',
        'voice_attributes', 'palette', 'typography',
        'evidence_sources', 'competitors', 'embedding',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'voice_attributes' => 'array',
            'palette' => 'array',
            'typography' => 'array',
            'evidence_sources' => 'array',
            'competitors' => 'array',
            'embedding' => Vector::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
