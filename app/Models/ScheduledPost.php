<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ScheduledPost extends Model
{
    protected $fillable = [
        'draft_id', 'brand_id', 'platform_connection_id',
        'scheduled_for', 'status', 'blotato_post_id',
        'platform_post_id', 'platform_post_url', 'last_error',
        'attempt_count', 'submitted_at', 'published_at',
        'queued_for_period_at',
    ];

    /**
     * last_error substrings that mark a PERMANENT failure — one that will
     * never self-heal on a blind retry, so the cron auto-retry path
     * (PostsDispatchDue branch 3) must SKIP it and the operator-facing
     * nextActionFor copy must point at the real fix (reconnect / re-run)
     * instead of promising "cron will retry".
     *
     * Each entry MUST be a substring of the exact string the corresponding
     * SubmitScheduledPost::markFailed() call produces — the producer↔matcher
     * agreement is locked by tests/Unit/PermanentFailureNoAutoRetryTest. If
     * you edit a markFailed message for one of these gates, update the
     * signature here in the same change or the matcher silently goes stale.
     *
     * Deliberately EXCLUDED (these stay transient → keep their 3 retries):
     *   - 'Platform rejected: ...'   provider 4xx/5xx poll rejection
     *   - '... submit failed: ...'    provider submit failure
     *   - 'Stuck in submitting ...'   already terminal by attempt_count
     */
    public const PERMANENT_FAILURE_SIGNATURES = [
        'connection is not active',            // revoked/expired/reauth_required gate
        'Draft or platform connection missing', // missing-draft gate
        'Publishability gate (pre-publish)',   // platform-rules gate
        'Video-format draft has a still image', // video-integrity gate (needs VideoAgent)
    ];

    /**
     * True iff $lastError names a permanent (non-self-healing) failure.
     * null/empty → false: an unknown failure keeps today's auto-retry
     * behaviour (fail-open on retry, never fail-closed).
     */
    public static function isPermanentFailureReason(?string $lastError): bool
    {
        if ($lastError === null || $lastError === '') {
            return false;
        }
        foreach (self::PERMANENT_FAILURE_SIGNATURES as $signature) {
            if (Str::contains($lastError, $signature, ignoreCase: true)) {
                return true;
            }
        }
        return false;
    }

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'queued_for_period_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->attempt_count < 3;
    }

    /**
     * True iff this row is failed for a permanent reason. Used by the cron
     * (skip auto-retry) and the resource (advisory copy). Note canRetry()
     * stays attempt-count-only on purpose: the operator's MANUAL Retry is an
     * explicit override — they may have just reconnected the platform.
     */
    public function isPermanentlyFailed(): bool
    {
        return $this->status === 'failed'
            && self::isPermanentFailureReason($this->last_error);
    }
}
