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
     * Resolve a media URL to the disk-relative storage key we can delete it by,
     * but ONLY when the URL is one WE host on a durable disk we control (R2 in
     * prod, the local `public` disk in dev). Returns null otherwise.
     *
     * This is the safety gate for the "Delete media" action: a draft's
     * asset_url may instead be a remote, provider-hosted URL (Blotato /
     * Metricool re-host, a customer-supplied link). We must never issue a
     * storage delete against a bucket we don't own — and we have no key mapping
     * for those — so a non-durable URL returns null and the caller skips the
     * file delete (it still clears the DB field).
     *
     * Matching is done against the disk's CONFIGURED public-URL base so it works
     * for both shapes without hardcoding a host:
     *   - R2:     <R2_PUBLIC_URL>/branding/388-abc.jpg   → "branding/388-abc.jpg"
     *   - public: <APP_URL>/storage/branding/388-abc.jpg → "branding/388-abc.jpg"
     * Query strings / fragments are stripped from the returned key.
     */
    public static function durableMediaKey(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $disk = config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
        $base = $disk === 'r2'
            ? (string) config('filesystems.disks.r2.url')
            : rtrim((string) config('filesystems.disks.public.url'), '/');

        $base = rtrim($base, '/');
        if ($base === '' || ! str_starts_with($url, $base.'/')) {
            return null;
        }

        $key = substr($url, strlen($base) + 1);
        // Strip any query string / fragment so the key is the bare object path.
        $key = (string) (parse_url($key, PHP_URL_PATH) ?: $key);
        $key = ltrim($key, '/');

        return $key !== '' ? $key : null;
    }

    /**
     * Stable fingerprint of the caption body used to tell whether the generated
     * media (still / video) still reflects the current text.
     *
     * Whitespace is collapsed and the text lower-cased so cosmetic edits (a
     * double space, a trailing newline) don't count as a content change and
     * force a needless paid regeneration — only a real wording change flips it.
     */
    public static function hashBody(?string $body): string
    {
        $normalised = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $body) ?? (string) $body));

        return sha1($normalised);
    }

    /** Convenience: the body hash for THIS draft's current body. */
    public function bodyHash(): string
    {
        return self::hashBody($this->body);
    }

    /**
     * The body hash the current media was generated from. Stamped into
     * branding_payload.media_body_hash by DesignerAgent when it persists an
     * asset; cleared (with the rest of branding_payload) when the caption is
     * edited. Null when no media has been generated, or when an older draft
     * predates this bookkeeping.
     */
    public function mediaBodyHash(): ?string
    {
        $payload = is_array($this->branding_payload) ? $this->branding_payload : [];
        $hash = $payload['media_body_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Whether the generated media is stale relative to the current caption —
     * i.e. there IS media, but it was generated from a different body than the
     * one stored now (the operator edited the caption afterward). When true,
     * the media should be regenerated from the edited text before being reused
     * (e.g. as a video keyframe) rather than animated as-is.
     *
     * Treats a MISSING media hash on a draft that has an asset as stale: such a
     * draft was either generated before this bookkeeping existed or had its
     * branding_payload cleared by an edit, and we'd rather pay one regeneration
     * than risk shipping an off-message visual. Returns false when there is no
     * media yet (nothing to be stale).
     */
    public function mediaIsStaleForBody(): bool
    {
        if (empty($this->asset_url)) {
            return false;
        }

        return $this->mediaBodyHash() !== $this->bodyHash();
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
