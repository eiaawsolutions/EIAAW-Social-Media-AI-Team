<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'slug',
        'name',
        'website_url',
        'industry',
        'locale',
        'timezone',
        'logo_url',
        'config',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Brand styles (versioned). One row per onboarding/refresh; current_version is the active row. */
    public function styles(): HasMany
    {
        return $this->hasMany(BrandStyle::class);
    }

    public function currentStyle(): HasOne
    {
        return $this->hasOne(BrandStyle::class)->where('is_current', true);
    }

    public function corpus(): HasMany
    {
        return $this->hasMany(BrandCorpusItem::class);
    }

    public function platformConnections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function embargoes(): HasMany
    {
        return $this->hasMany(Embargo::class);
    }

    public function bannedPhrases(): HasMany
    {
        return $this->hasMany(BannedPhrase::class);
    }

    public function autonomySettings(): HasMany
    {
        return $this->hasMany(AutonomySetting::class);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(ContentCalendar::class);
    }

    public function calendarEntries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function performanceUploads(): HasMany
    {
        return $this->hasMany(PerformanceUpload::class);
    }

    protected function isArchived(): Attribute
    {
        return Attribute::get(fn () => $this->archived_at !== null);
    }

    /** Default lane for a platform (or global default if no platform-specific row). */
    public function defaultLaneFor(string $platform): string
    {
        $platformSetting = $this->autonomySettings()->where('platform', $platform)->first();
        if ($platformSetting) {
            return $platformSetting->default_lane;
        }
        return $this->autonomySettings()->whereNull('platform')->value('default_lane') ?? 'amber';
    }
}
