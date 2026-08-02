<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply the CommunityAgent drafted for an inbound conversation.
 *
 * Nothing here reaches a real person without a human clicking approve. That is
 * deliberate and it is the whole safety model for L4: every other actuator in
 * the growth ladder only changes what we PLAN, but this one sends a message to
 * an individual under the brand's name, which is not reversible and not
 * something a compliance score should be trusted to gate on its own.
 */
class InboxReplyDraft extends Model
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SENT = 'sent';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    /** Statuses that still occupy the conversation — don't draft a second one. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
    ];

    protected $fillable = [
        'inbox_conversation_id', 'brand_id', 'workspace_id',
        'body', 'status', 'recommends_no_reply', 'reasoning',
        'approved_by_user_id', 'approved_at', 'sent_at', 'last_error',
        'model_id', 'prompt_version', 'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'recommends_no_reply' => 'boolean',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'cost_usd' => 'float',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopeAwaitingHuman(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function scopeSendable(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED)->where('recommends_no_reply', false);
    }
}
