<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead/enquiry from the floating "Talk to us" form (landing, client, or HQ
 * surface). Stores only submitted-by-the-visitor data — see the migration and
 * the global Lead Generation Contract. No fabricated contact fields.
 */
class SupportEnquiry extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'surface',
        'name', 'email', 'phone', 'company', 'message',
        'ip_hash', 'user_agent', 'referer',
        'status', 'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
