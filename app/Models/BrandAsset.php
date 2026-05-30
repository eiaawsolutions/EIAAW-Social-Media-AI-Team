<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class BrandAsset extends Model
{
    use HasNeighbors;

    /** Upload intents. general = agent pool; customised = reserved for one dedicated post. */
    public const INTENT_GENERAL = 'general';
    public const INTENT_CUSTOMISED = 'customised';

    protected $fillable = [
        'brand_id', 'uploaded_by_user_id',
        'media_type', 'source', 'usage_intent',
        'scheduled_platforms', 'scheduled_post_for', 'narrative_source',
        'customised_calendar_entry_id',
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
            'scheduled_platforms' => 'array',
            'scheduled_post_for' => 'datetime',
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

    /** The calendar entry that drives this asset's dedicated post(s), if customised. */
    public function customisedCalendarEntry(): BelongsTo
    {
        return $this->belongsTo(CalendarEntry::class, 'customised_calendar_entry_id');
    }

    public function isImage(): bool { return $this->media_type === 'image'; }
    public function isVideo(): bool { return $this->media_type === 'video'; }

    public function isCustomised(): bool { return $this->usage_intent === self::INTENT_CUSTOMISED; }
    public function isGeneral(): bool { return $this->usage_intent !== self::INTENT_CUSTOMISED; }

    /** Only general-usage assets feed the agent picker pool. */
    public function scopeGeneralPool(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('usage_intent', self::INTENT_GENERAL);
    }

    /** Bump usage counters after the Picker selects this asset. */
    public function recordUse(): void
    {
        $this->increment('use_count');
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
