<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * compliance_legal_rules — the curated, citable legal/advertising-standards
 * rulebook the agents reason over, keyed by (industry, jurisdiction).
 *
 * Sibling of compliance_learned_rules (auto-grown platform memory). The
 * difference: learned_rules grow from real publish rejections; legal_rules are
 * SEEDED by curation (ComplianceLegalRuleSeeder) from real laws/standards with
 * a `source` citation per row (truthfulness contract — every rule traceable).
 *
 * The `directive` column is the operator-readable "do not / must" line that
 * LegalRulesProvider injects into THREE consumers, all from this one table:
 *   1. StrategistAgent prompt — so the calendar is PLANNED compliant.
 *   2. WriterAgent prompt — so each post is DRAFTED compliant.
 *   3. ComplianceAgent's legal_compliance check — the backstop gate.
 *
 * Scope: jurisdiction = '*' is a global rule (applies to every brand in this
 * industry regardless of country); a specific code (e.g. 'MY', 'SG') applies
 * only to brands whose primary business location resolves to that jurisdiction.
 * industry = '*' likewise means "every industry" (cross-industry ad standards).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('compliance_legal_rules', function (Blueprint $t) {
            $t->id();

            // Canonical industry key from App\Support\Compliance\IndustryCatalog
            // (e.g. 'financial_services', 'food_beverage'). '*' = all industries.
            $t->string('industry', 60)->index();

            // Jurisdiction key derived from the brand's primary business
            // location (e.g. 'MY', 'SG'). '*' = global / jurisdiction-agnostic.
            $t->string('jurisdiction', 10)->index();

            // Stable human-authored identifier, e.g. 'MY-FIN-001'. Used as the
            // seeder's idempotency key and cited back in violation reports so an
            // operator can trace a block to a specific rule.
            $t->string('rule_code', 40);

            $t->string('title');

            // The rule, phrased as an instruction the planner/writer obeys and
            // the judge enforces (e.g. "Never promise guaranteed or fixed
            // investment returns").
            $t->text('directive');

            // 'block' = a violation hard-fails the compliance gate (and the
            // planner/writer must avoid it). 'advisory' = surfaced in prompts &
            // flagged by the judge but does NOT block — for grey-area guidance.
            $t->enum('severity', ['block', 'advisory'])->default('block');

            // Optional few-shot guidance: {"violating": [...], "compliant": [...]}.
            $t->json('examples')->nullable();

            // Citation for auditability — the act/regulator/standard this rule
            // derives from. Required by curation discipline; nullable at the DB
            // layer only so a draft seed isn't blocked mid-authoring.
            $t->string('source')->nullable();

            // Operator override: disable a rule (false-positive) without deleting
            // it, so the seeder's updateOrCreate won't silently resurrect it on
            // the next deploy (the seeder preserves `disabled`).
            $t->boolean('disabled')->default(false)->index();

            $t->timestamps();

            // Seeder upserts on this tuple; also the natural uniqueness of a rule.
            $t->unique(['industry', 'jurisdiction', 'rule_code'], 'compliance_legal_rules_unique');

            // The provider's hot-path lookup: rules for (industry, jurisdiction)
            // plus the global fallbacks, enabled only.
            $t->index(['industry', 'jurisdiction', 'disabled'], 'compliance_legal_rules_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_legal_rules');
    }
};
