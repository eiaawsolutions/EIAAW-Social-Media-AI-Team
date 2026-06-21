<?php

namespace Database\Seeders;

use App\Models\ComplianceLegalRule;
use Illuminate\Database\Seeder;

/**
 * Curated starter rulebook for the legal compliance feature. Idempotent:
 * upserts on (industry, jurisdiction, rule_code) and PRESERVES an operator's
 * `disabled` flag, so re-running on every deploy is safe and never resurrects a
 * rule a human turned off.
 *
 * Scope of this seed (per plan §7): the app's actual footprint — Malaysia (MY)
 * primary, plus jurisdiction-agnostic global ('*') advertising-standards basics
 * that apply to every brand. Each row carries a `source` citation; this is a
 * DEFENSIBLE STARTER SET for operator/legal review, not an exhaustive code of
 * law. The LLM judge also applies well-established law beyond these rows; these
 * seeded [block] rules are the deterministic backbone.
 *
 * Safe to extend: add rows for new (industry, jurisdiction) pairs over time.
 */
class ComplianceLegalRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rules() as $rule) {
            ComplianceLegalRule::query()->updateOrCreate(
                [
                    'industry' => $rule['industry'],
                    'jurisdiction' => $rule['jurisdiction'],
                    'rule_code' => $rule['rule_code'],
                ],
                [
                    'title' => $rule['title'],
                    'directive' => $rule['directive'],
                    'severity' => $rule['severity'] ?? 'block',
                    'examples' => $rule['examples'] ?? null,
                    'source' => $rule['source'] ?? null,
                    // Intentionally NOT setting `disabled` here — preserve any
                    // operator override across re-seeds.
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rules(): array
    {
        return [
            // ── Global / cross-industry advertising standards ('*' / '*') ─────
            [
                'industry' => '*', 'jurisdiction' => '*', 'rule_code' => 'GL-AD-001',
                'title' => 'No false or misleading claims',
                'directive' => 'Never make a false, deceptive, or misleading factual claim about a product, service, price, or outcome. Every factual claim must be truthful and substantiable.',
                'severity' => 'block',
                'source' => 'ICC Advertising & Marketing Communications Code (general principle)',
            ],
            [
                'industry' => '*', 'jurisdiction' => '*', 'rule_code' => 'GL-AD-002',
                'title' => 'Substantiate comparative & superlative claims',
                // Scope: this targets UNVERIFIABLE puffery and claims ABOUT RIVALS
                // ("the best", "No.1", "cheapest", "fastest", guaranteed outcomes),
                // not a brand plainly describing its OWN product. A first-party
                // statement of what your own product does, includes, or is built
                // from (e.g. "runs six agents", "ships with a source and a score")
                // is a verifiable factual claim the advertiser substantiates by
                // building the product — it is NOT a superlative and must not be
                // held under this rule. (Fixed a false-positive where Art. 5 was
                // over-applied to ordinary first-party feature copy.)
                'directive' => 'Do not use UNVERIFIABLE superlatives ("the best", "No.1", "cheapest", "fastest") or comparative claims about competitors, or promise guaranteed outcomes, unless they are objectively true and substantiable. This does NOT restrict a brand from plainly describing its OWN product\'s checkable features, components, or how it works — first-party factual feature claims are permitted.',
                'severity' => 'block',
                'source' => 'ICC Code, Art. 5 (substantiation)',
            ],
            [
                'industry' => '*', 'jurisdiction' => '*', 'rule_code' => 'GL-AD-003',
                'title' => 'Disclose paid / sponsored content',
                'directive' => 'Clearly disclose when content is a paid promotion, sponsorship, or contains affiliate links (e.g. #ad, #sponsored). Do not disguise advertising as independent editorial.',
                'severity' => 'advisory',
                'source' => 'ICC Code, Art. 7 (identification of marketing communications)',
            ],

            // ── Malaysia (MY) ── Food & Beverage ──────────────────────────────
            [
                'industry' => 'food_beverage', 'jurisdiction' => 'MY', 'rule_code' => 'MY-FNB-001',
                'title' => 'No unsubstantiated health/nutrition claims',
                'directive' => 'Do not claim a food or drink prevents, treats, or cures any disease, or make a nutrient/health claim that is not permitted and substantiated under the Malaysian Food Regulations 1985.',
                'severity' => 'block',
                'source' => 'Food Regulations 1985 (MY), reg. on claims & advertisement',
            ],
            [
                'industry' => 'food_beverage', 'jurisdiction' => 'MY', 'rule_code' => 'MY-FNB-002',
                'title' => 'Halal claims must be accurate',
                'directive' => 'Only state or imply a product is "halal" if it holds valid JAKIM (or recognised) halal certification. Never imply halal status without certification.',
                'severity' => 'block',
                'source' => 'Trade Descriptions (Definition of Halal) Order 2011 (MY)',
            ],

            // ── Malaysia (MY) ── Healthcare / Medical ─────────────────────────
            [
                'industry' => 'healthcare', 'jurisdiction' => 'MY', 'rule_code' => 'MY-HLTH-001',
                'title' => 'No disease cure/prevention claims without approval',
                'directive' => 'Do not advertise that any product or service cures, treats, or prevents disease unless it is permitted and approved. Medicine advertisements require Medicine Advertisements Board (MAB) approval.',
                'severity' => 'block',
                'source' => 'Medicines (Advertisement and Sale) Act 1956 (MY)',
            ],
            [
                'industry' => 'healthcare', 'jurisdiction' => 'MY', 'rule_code' => 'MY-HLTH-002',
                'title' => 'No misleading testimonials or guaranteed results',
                'directive' => 'Do not use patient testimonials, before/after claims, or "guaranteed results" language for medical treatments without substantiation and required approvals.',
                'severity' => 'block',
                'source' => 'Medicines (Advertisement and Sale) Act 1956 (MY); MMC guidelines',
            ],

            // ── Malaysia (MY) ── Beauty & Cosmetics ───────────────────────────
            [
                'industry' => 'beauty_cosmetics', 'jurisdiction' => 'MY', 'rule_code' => 'MY-BTY-001',
                'title' => 'No medicinal/therapeutic claims for cosmetics',
                'directive' => 'Do not make medicinal or therapeutic claims for a cosmetic (e.g. "cures acne", "removes scars permanently"). Cosmetic claims must stay within cosmetic effect and be substantiated.',
                'severity' => 'block',
                'source' => 'Control of Drugs and Cosmetics Regulations 1984 (MY); NPRA guidelines',
            ],
            [
                'industry' => 'beauty_cosmetics', 'jurisdiction' => 'MY', 'rule_code' => 'MY-BTY-002',
                'title' => 'Before/after results must be substantiated',
                'directive' => 'Do not present before/after imagery or results claims as typical outcomes unless substantiated and not misleading.',
                'severity' => 'advisory',
                'source' => 'ICC Code substantiation principle; NPRA cosmetic claim guidance',
            ],

            // ── Malaysia (MY) ── Financial Services ───────────────────────────
            [
                'industry' => 'financial_services', 'jurisdiction' => 'MY', 'rule_code' => 'MY-FIN-001',
                'title' => 'No guaranteed/fixed return promises',
                'directive' => 'Never promise guaranteed, fixed, or risk-free investment returns. Do not understate or omit investment risk. Returns claims must be balanced with risk disclosure.',
                'severity' => 'block',
                'source' => 'Capital Markets & Services Act 2007 (MY); SC guidelines on advertising',
            ],
            [
                'industry' => 'financial_services', 'jurisdiction' => 'MY', 'rule_code' => 'MY-FIN-002',
                'title' => 'Licensed-activity & disclosure requirements',
                'directive' => 'Do not promote regulated financial products/services implying authorisation you do not hold, and include risk disclosures/disclaimers required by BNM/SC for the product type.',
                'severity' => 'block',
                'source' => 'BNM / Securities Commission Malaysia promotional disclosure requirements',
            ],
        ];
    }
}
