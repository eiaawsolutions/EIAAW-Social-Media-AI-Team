<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCalendar extends Model
{
    /**
     * Label of the single reusable per-brand calendar that holds OPERATOR-pinned
     * customised posts (CustomisedPostScheduler). It is deliberately NOT part of
     * the autonomous content plan: the Strategist neither writes to it nor should
     * read from it, and ContentAutopilot must not count its entries as plan
     * coverage. See ContentAutopilot::autonomousCalendarIds().
     */
    public const CUSTOMISED_LABEL = 'Customised posts';

    protected $fillable = [
        'brand_id', 'label', 'period_starts_on', 'period_ends_on',
        'pillar_mix', 'format_mix', 'platform_mix', 'status',
        'created_by_user_id', 'approved_by_user_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'pillar_mix' => 'array',
            'format_mix' => 'array',
            'platform_mix' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CalendarEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Is this calendar part of the autonomous content plan (as opposed to the
     * operator's customised-post shelf)? Pure + static so the rule is unit-
     * testable without a database.
     */
    public static function isAutonomousLabel(?string $label): bool
    {
        return $label !== self::CUSTOMISED_LABEL;
    }

    /** The Strategist-built calendars — i.e. everything the autonomous plan owns. */
    public function scopeAutonomous($query)
    {
        return $query->where('label', '!=', self::CUSTOMISED_LABEL);
    }

    /** The operator-pinned customised-post calendar. */
    public function scopeCustomised($query)
    {
        return $query->where('label', self::CUSTOMISED_LABEL);
    }
}
