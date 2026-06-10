<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Draft extends Model
{
    protected $fillable = [
        'brand_id', 'calendar_entry_id', 'parent_draft_id',
        'platform', 'content_type',
        'body', 'platform_payload', 'hashtags', 'mentions',
        'asset_url', 'asset_urls', 'video_aspect_ratio', 'branding_payload',
        // Provenance
        'agent_role', 'model_id', 'prompt_version', 'prompt_inputs',
        'grounding_sources', 'competitor_refs',
        'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms',
        // Approval
        'status', 'lane',
        'approved_by_user_id', 'approved_at',
        'rejected_by_user_id', 'rejected_at', 'rejection_reason',
        // Auto-redraft bookkeeping
        'revision_count', 'last_redraft_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_payload' => 'array',
            'hashtags' => 'array',
            'mentions' => 'array',
            'asset_urls' => 'array',
            'branding_payload' => 'array',
            'prompt_inputs' => 'array',
            'grounding_sources' => 'array',
            'competitor_refs' => 'array',
            'cost_usd' => 'decimal:6',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_redraft_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function calendarEntry(): BelongsTo
    {
        return $this->belongsTo(CalendarEntry::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Draft::class, 'parent_draft_id');
    }

    public function derivatives(): HasMany
    {
        return $this->hasMany(Draft::class, 'parent_draft_id');
    }

    public function complianceChecks(): HasMany
    {
        return $this->hasMany(ComplianceCheck::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function passedAllCompliance(): bool
    {
        return ! $this->complianceChecks()->where('result', 'fail')->exists();
    }

    public function isHeld(): bool
    {
        return in_array($this->status, ['compliance_pending', 'compliance_failed', 'awaiting_approval']);
    }

    /**
     * File extensions we treat as video when classifying a media URL. Query
     * strings and fragments are stripped before matching.
     */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'];

    /**
     * Whether a media URL points at a video file (by extension). Used to decide
     * whether asset_url can be rendered in an <img> thumbnail.
     */
    public static function urlIsVideo(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    /** True when the draft's primary asset is a video. */
    public function hasVideoAsset(): bool
    {
        return self::urlIsVideo($this->asset_url);
    }

    /**
     * A URL safe to render inside an <img> thumbnail.
     *
     * asset_url is the PUBLISHABLE media — for video drafts that's an .mp4,
     * which an <img> can't display (the empty-box bug in the drafts list). So:
     *   - asset_url is an image            → use it
     *   - asset_url is a video             → fall back to the most recent IMAGE
     *                                        in asset_urls (the keyframe the
     *                                        Designer/Video agents store)
     *   - neither                          → null (caller shows a placeholder)
     */
    public function displayThumbnailUrl(): ?string
    {
        if ($this->asset_url && ! self::urlIsVideo($this->asset_url)) {
            return $this->asset_url;
        }

        $history = is_array($this->asset_urls) ? $this->asset_urls : [];
        foreach (array_reverse($history) as $url) {
            if (is_string($url) && $url !== '' && ! self::urlIsVideo($url)) {
                return $url;
            }
        }

        return null;
    }
}
