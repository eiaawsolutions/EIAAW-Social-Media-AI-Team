<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannedPhrase extends Model
{
    protected $fillable = [
        'brand_id', 'phrase', 'is_regex', 'case_sensitive', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'is_regex' => 'boolean',
            'case_sensitive' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function matches(string $content): bool
    {
        if ($this->is_regex) {
            $flags = $this->case_sensitive ? '' : 'i';
            return (bool) @preg_match('/' . str_replace('/', '\/', $this->phrase) . '/' . $flags, $content);
        }
        return $this->case_sensitive
            ? str_contains($content, $this->phrase)
            : stripos($content, $this->phrase) !== false;
    }
}
