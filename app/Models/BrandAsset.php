<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Does the underlying file still exist on its storage disk?
     *
     * Brand assets uploaded to a non-durable disk (the local `public` disk on a
     * stateless Railway container) are wiped on every redeploy, leaving a row
     * whose `public_url` is well-formed but whose bytes are gone. Previewing
     * such an asset renders a broken-image glyph. This lets a caller detect the
     * missing-bytes case and show an honest placeholder instead.
     *
     * NOTE: this performs a disk stat (a remote HEAD on S3/R2). It is cheap for
     * a single record (a modal preview) but MUST NOT be called per-row in a
     * table render — that would be N network round-trips. Callers in list
     * contexts should rely on the URL + a client-side onerror fallback instead.
     */
    public function bytesAvailable(): bool
    {
        if (! $this->storage_disk || ! $this->storage_path) {
            return false;
        }
        try {
            return Storage::disk($this->storage_disk)->exists($this->storage_path);
        } catch (\Throwable) {
            // Disk misconfigured / unreachable — treat as unavailable rather
            // than throw inside a view. The placeholder is the safe default.
            return false;
        }
    }

    /**
     * A URL safe to drop into an <img>/<video> src, or null when there is
     * nothing displayable. Returns null for a blank/whitespace `public_url`
     * so callers can branch to a placeholder instead of emitting `src=""`
     * (which browsers resolve to the current page and render as broken).
     */
    public function displayUrl(): ?string
    {
        $url = trim((string) ($this->public_url ?? ''));

        return $url !== '' ? $url : null;
    }

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
