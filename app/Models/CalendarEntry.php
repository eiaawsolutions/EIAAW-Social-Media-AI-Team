<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEntry extends Model
{
    protected $fillable = [
        'content_calendar_id', 'brand_id', 'scheduled_date', 'scheduled_time',
        'topic', 'angle', 'pillar', 'format', 'platforms', 'objective',
        'visual_direction', 'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'platforms' => 'array',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ContentCalendar::class, 'content_calendar_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }
}
