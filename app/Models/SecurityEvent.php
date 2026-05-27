<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only ledger for security-relevant events: prompt injection,
 * suspected tool abuse, output leaks, auth anomalies.
 *
 * Two walls enforce immutability:
 *   - App layer: update() and delete() throw RuntimeException (this class)
 *   - DB layer: Postgres triggers block UPDATE/DELETE (migration)
 *
 * Always insert via the PromptInjectionDetector or future security
 * services — never construct rows directly from controllers / Filament.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property int|null $brand_id
 * @property int|null $user_id
 * @property string $event_type
 * @property 'low'|'medium'|'high' $severity
 * @property string|null $detector_layer
 * @property string|null $verdict
 * @property int|null $confidence
 * @property string|null $category
 * @property string|null $evidence
 * @property array|null $payload
 * @property string|null $correlation_id
 * @property bool $blocked
 * @property bool $alerted
 * @property \Illuminate\Support\Carbon $occurred_at
 */
class SecurityEvent extends Model
{
    protected $fillable = [
        'workspace_id', 'brand_id', 'user_id',
        'event_type', 'severity', 'detector_layer', 'verdict',
        'confidence', 'category', 'evidence', 'payload',
        'correlation_id', 'blocked', 'alerted', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'blocked' => 'boolean',
            'alerted' => 'boolean',
            'confidence' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hard-block app-layer mutations. The DB trigger is the second wall. */
    public function update(array $attributes = [], array $options = [])
    {
        throw new RuntimeException('security_events rows are append-only');
    }

    public function delete()
    {
        throw new RuntimeException('security_events rows are append-only');
    }
}
