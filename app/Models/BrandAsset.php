<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class BrandAsset extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'brand_id', 'uploaded_by_user_id',
        'media_type', 'source',
        'storage_disk', 'storage_path', 'public_url', 'thumbnail_url',
        'original_filename', 'mime_type', 'file_size_bytes',
        'width_px', 'height_px', 'duration_seconds',
        'tags', 'description', 'embedding',
        'brand_approved', 'use_count', 'last_used_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'brand_approved' => 'boolean',
            'last_used_at' => 'datetime',
            'archived_at' => 'datetime',
            'embedding' => Vector::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isImage(): bool { return $this->media_type === 'image'; }
    public function isVideo(): bool { return $this->media_type === 'video'; }

    /** Bump usage counters after the Picker selects this asset. */
    public function recordUse(): void
    {
        $this->increment('use_count');
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
