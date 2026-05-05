<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per learned rejection pattern. See
 * 2026_05_05_150000_create_compliance_learned_rules_table.php for column
 * semantics. Touched by App\Services\Compliance\LearnedRulesRecorder
 * (writes) and App\Services\Compliance\LearnedRulesProvider (reads).
 */
class ComplianceLearnedRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'platform',
        'rule_kind',
        'fingerprint',
        'severity',
        'directive',
        'rejection_excerpt',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'last_draft_id',
        'last_scheduled_post_id',
        'disabled',
        'operator_note',
    ];

    protected $casts = [
        'occurrences' => 'integer',
        'disabled' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Confidence promotes from warn → block once we've seen the fingerprint
     * recur on a new draft. A single occurrence is a warning (could be a
     * platform glitch); two or more is a real pattern.
     */
    public function recommendedSeverity(): string
    {
        return $this->occurrences >= 2 ? 'block' : 'warn';
    }
}
