<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCalendar extends Model
{
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
}
