<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Embargo extends Model
{
    protected $table = 'embargoes';

    protected $fillable = [
        'brand_id', 'label', 'description', 'starts_at', 'ends_at',
        'topic_keywords', 'action', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'topic_keywords' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isActive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?? now();
        return $this->starts_at <= $at && $this->ends_at >= $at;
    }

    public function matches(string $content): bool
    {
        $haystack = strtolower($content);
        foreach ($this->topic_keywords as $kw) {
            if (str_contains($haystack, strtolower($kw))) {
                return true;
            }
        }
        return false;
    }
}
