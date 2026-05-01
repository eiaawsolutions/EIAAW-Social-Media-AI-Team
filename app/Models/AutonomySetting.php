<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutonomySetting extends Model
{
    protected $fillable = [
        'brand_id', 'platform', 'default_lane', 'green_lane_rules', 'red_lane_rules',
    ];

    protected function casts(): array
    {
        return [
            'green_lane_rules' => 'array',
            'red_lane_rules' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
