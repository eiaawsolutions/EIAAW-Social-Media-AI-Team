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
        'metricool_blog_id',
        'metricool_connect_link_sent_at',
        'metricool_connected_at',
        'metricool_connect_url',
        'config',
        'competitors',
        'competitor_intel_config',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'competitors' => 'array',
            'competitor_intel_config' => 'array',
            'archived_at' => 'datetime',
            'metricool_connect_link_sent_at' => 'datetime',
            'metricool_connected_at' => 'datetime',
        ];
    }

    /**
     * Derived Metricool onboarding state — the Metricool analogue of
     * Workspace::blotatoSetupState(). Drives the MetricoolSetup wizard.
     *
     *   'not_mapped'  → no metricool_blog_id; operator must create + map a
     *                   Metricool brand for this SMT brand.
     *   'link_sent'   → mapped + the connect-link was shared; waiting for the
     *                   customer to connect their socials inside Metricool.
     *   'mapped'      → mapped but no link sent yet (operator generates one).
     *   'connected'   → /admin/profile reported ≥1 connected network.
     */
    public function metricoolSetupState(): string
    {
        if ($this->hasMetricoolConnected()) {
            return 'connected';
        }
        if (empty($this->metricool_blog_id)) {
            return 'not_mapped';
        }
        if ($this->metricool_connect_link_sent_at !== null) {
            return 'link_sent';
        }
        return 'mapped';
    }

    /** True once ≥1 social network has been detected connected in Metricool. */
    public function hasMetricoolConnected(): bool
    {
        return ! empty($this->metricool_blog_id)
            && $this->metricool_connected_at !== null;
    }

    /**
     * The durable per-brand Metricool "manage connections" link — where the
     * customer goes to add/remove the actual social accounts at the source.
     *
     * Returns the stored share/manage link if it's a valid https URL, else null.
     * Null means the wizard must fall back to the "request a fresh link" flow
     * rather than dead-ending the "Manage connections" button. We never invent
     * an app.metricool.com URL here — there is no per-customer login and the
     * agency dashboard is shared across all tenants ([[metricool-multitenancy]]),
     * so the only safe destination is this brand's own share-link.
     */
    public function metricoolManageUrl(): ?string
    {
        $url = trim((string) $this->metricool_connect_url);
        if ($url === '') {
            return null;
        }

        return (filter_var($url, FILTER_VALIDATE_URL) && str_starts_with($url, 'https://'))
            ? $url
            : null;
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

    public function competitorAds(): HasMany
    {
        return $this->hasMany(CompetitorAd::class);
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
