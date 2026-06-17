<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per curated legal/advertising-standards rule, keyed by
 * (industry, jurisdiction, rule_code). See
 * 2026_06_15_120000_create_compliance_legal_rules_table.php for column
 * semantics. Seeded by App\Database\Seeders\ComplianceLegalRuleSeeder and read
 * by App\Services\Compliance\LegalRulesProvider (which feeds the Strategist,
 * Writer, and Compliance agents from this single table).
 *
 * Sibling of ComplianceLearnedRule (auto-grown platform memory).
 */
class ComplianceLegalRule extends Model
{
    use HasFactory;

    /** Sentinel used for "applies to every industry / every jurisdiction". */
    public const WILDCARD = '*';

    protected $fillable = [
        'industry',
        'jurisdiction',
        'rule_code',
        'title',
        'directive',
        'severity',
        'examples',
        'source',
        'disabled',
    ];

    protected $casts = [
        'examples' => 'array',
        'disabled' => 'boolean',
    ];

    /**
     * Any create/edit/toggle/delete (operator Filament resource, CLI, seeder)
     * invalidates the LegalRulesProvider cache so the change is applied on the
     * next agent run rather than waiting out the 60s TTL. Centralised here so
     * every write path busts the cache without each caller remembering to.
     */
    protected static function booted(): void
    {
        $flush = fn () => app(\App\Services\Compliance\LegalRulesProvider::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    public function isBlocking(): bool
    {
        return $this->severity === 'block';
    }
}
