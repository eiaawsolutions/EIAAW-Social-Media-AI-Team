<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only row per legal-acceptance event. Created server-side only
 * (via User::recordLegalAcceptance) — never updated, never deleted except by
 * the user's own cascade. This is the evidentiary record of consent.
 *
 * @property int $user_id
 * @property string $document_version
 * @property \Illuminate\Support\Carbon $accepted_at
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?array $documents_json
 * @property string $source
 */
class LegalAcceptance extends Model
{
    protected $fillable = [
        'user_id',
        'document_version',
        'accepted_at',
        'ip_address',
        'user_agent',
        'documents_json',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'documents_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
