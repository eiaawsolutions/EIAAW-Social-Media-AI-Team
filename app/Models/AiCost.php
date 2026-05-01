<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCost extends Model
{
    protected $fillable = [
        'brand_id', 'workspace_id', 'draft_id',
        'agent_role', 'provider', 'model_id',
        'input_tokens', 'output_tokens', 'image_count',
        'cost_usd', 'cost_myr', 'called_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'cost_myr' => 'decimal:4',
            'called_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }
}
