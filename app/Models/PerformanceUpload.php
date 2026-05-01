<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceUpload extends Model
{
    protected $fillable = [
        'brand_id', 'uploaded_by_user_id', 'source', 'platform',
        'period_starts_on', 'period_ends_on',
        'original_filename', 'file_url', 'parsed_data', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'parsed_data' => 'array',
            'summary' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
