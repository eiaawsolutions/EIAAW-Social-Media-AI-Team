<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One inbound engagement thread — a DM conversation or a comment on our post —
 * mirrored from Metricool's inbox.
 *
 * This is the read half of the L4 growth actuator. Replying is the only lever
 * the system has that reaches a specific human rather than broadcasting at an
 * audience, and (for comments) it is publicly visible to non-followers, which is
 * the only kind of reach that moves a follower goal.
 */
class InboxConversation extends Model
{
    public const TYPE_DM = 'dm';
    public const TYPE_COMMENT = 'comment';
    public const TYPE_REVIEW = 'review';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_READ = 'READ';

    /**
     * Platform reply windows, in hours, per Metricool's documentation. These are
     * hard deadlines enforced by the platforms, not guidance: a reply drafted
     * after the window has closed cannot be delivered, so drafting one is wasted
     * spend and a false promise on the dashboard.
     *
     * TYPE_REVIEW is DELIBERATELY ABSENT. Google Business Profile reviews carry
     * no reply deadline, and windowExpiryFor() already returns null for an
     * unlisted type while windowIsOpen() treats null as open — which is exactly
     * right. Adding a fabricated deadline here would expire replies the platform
     * would still have accepted.
     */
    public const WINDOW_HOURS = [
        self::TYPE_COMMENT => 24,
        self::TYPE_DM => 168, // 7 days
    ];

    protected $fillable = [
        'brand_id', 'workspace_id', 'conversation_type', 'provider',
        'external_id', 'recipient_external_id', 'participant_name',
        'status', 'last_message_text', 'last_message_from_us', 'last_message_at',
        'window_expires_at', 'our_last_reply_at', 'message_count', 'raw', 'first_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_from_us' => 'boolean',
            'last_message_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'our_last_reply_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'message_count' => 'integer',
            'raw' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function replyDrafts(): HasMany
    {
        return $this->hasMany(InboxReplyDraft::class);
    }

    /**
     * When the reply window closes for a thread whose last inbound message
     * arrived at $lastMessageAt. Null when we don't know the timestamp — an
     * unknown deadline must not be rendered as an imminent one.
     *
     * Pure, so window arithmetic is unit-testable without a DB or a fixed clock.
     */
    public static function windowExpiryFor(string $type, ?Carbon $lastMessageAt): ?Carbon
    {
        if ($lastMessageAt === null) {
            return null;
        }
        $hours = self::WINDOW_HOURS[$type] ?? null;

        return $hours === null ? null : $lastMessageAt->copy()->addHours($hours);
    }

    /** True when the platform will still accept a reply. Unknown window => assume open. */
    public function windowIsOpen(?Carbon $now = null): bool
    {
        if ($this->window_expires_at === null) {
            return true;
        }

        return $this->window_expires_at->greaterThan($now ?? now());
    }

    /**
     * Threads genuinely waiting on us: still PENDING, the last message came from
     * THEM, and the platform will still accept a reply.
     *
     * The last_message_from_us clause matters — a thread can sit at PENDING when
     * the most recent message is our own (prod brand#1 was exactly that), and
     * drafting a reply to ourselves is noise.
     */
    public function scopeAwaitingReply(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING)
            ->where('last_message_from_us', false)
            ->where(function (Builder $w) {
                $w->whereNull('window_expires_at')->orWhere('window_expires_at', '>', now());
            });
    }

    /** Past the platform deadline — surfaced honestly rather than silently dropped. */
    public function scopeWindowExpired(Builder $q): Builder
    {
        return $q->whereNotNull('window_expires_at')->where('window_expires_at', '<=', now());
    }
}
