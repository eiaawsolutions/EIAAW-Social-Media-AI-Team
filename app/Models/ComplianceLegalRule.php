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

    public function isBlocking(): bool
    {
        return $this->severity === 'block';
    }
}
