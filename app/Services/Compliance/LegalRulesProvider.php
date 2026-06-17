<?php

namespace App\Services\Compliance;

use App\Models\ComplianceLegalRule;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side service over the curated compliance_legal_rules table. Modeled on
 * LearnedRulesProvider — the same cache + scoped-query + prompt-directive shape
 * — but keyed by (industry, jurisdiction) instead of (platform, workspace).
 *
 * ONE source of truth for THREE consumers:
 *   1. StrategistAgent  — promptDirectiveFor(...) planted in the planning prompt
 *      so the calendar is born compliant.
 *   2. WriterAgent      — same directive in the drafting prompt so each post is
 *      written within the rules.
 *   3. ComplianceAgent  — activeRulesFor(...) + the directive in the backstop
 *      legal_compliance check.
 *
 * Scope merge: a brand in (industry=X, jurisdiction=Y) sees its specific rules
 * PLUS global fallbacks — rules with industry='*' (cross-industry ad standards)
 * and/or jurisdiction='*' (jurisdiction-agnostic). Disabled rules are excluded.
 *
 * Output is cached 60s per (industry, jurisdiction) to keep the planner/writer
 * hot path to one DB hit per minute; cache busts on operator rule edits.
 */
class LegalRulesProvider
{
    private const CACHE_TTL_SECONDS = 60;

    /** Hard cap — these go into LLM prompts, so prompt size matters. */
    private const MAX_RULES = 20;

    /**
     * Enabled rules for (industry, jurisdiction) merged with global fallbacks.
     * Block-severity rules first (they're the ones that hard-fail), then by
     * rule_code for stable ordering.
     *
     * @return \Illuminate\Support\Collection<int, ComplianceLegalRule>
     */
    public function activeRulesFor(string $industry, string $jurisdiction)
    {
        $industry = $industry !== '' ? $industry : ComplianceLegalRule::WILDCARD;
        $jurisdiction = $jurisdiction !== '' ? $jurisdiction : ComplianceLegalRule::WILDCARD;
        $key = sprintf('legal_rules:%s:%s', $industry, $jurisdiction);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($industry, $jurisdiction) {
            $wildcard = ComplianceLegalRule::WILDCARD;

            return ComplianceLegalRule::query()
                ->where('disabled', false)
                ->whereIn('industry', array_unique([$industry, $wildcard]))
                ->whereIn('jurisdiction', array_unique([$jurisdiction, $wildcard]))
                // 'block' sorts before 'advisory' alphabetically — which is the
                // order we want (blocking rules first).
                ->orderBy('severity')
                ->orderBy('rule_code')
                ->limit(self::MAX_RULES)
                ->get();
        });
    }

    /**
     * Build the markdown directive block injected into Strategist/Writer/
     * Compliance prompts. Returns '' when there are no rules so the prompt
     * template suppresses the section entirely (keeping an un-curated brand's
     * prompt byte-identical to the pre-feature behaviour) — same idiom as
     * LearnedRulesProvider::promptDirectiveFor and Brand::brandFactsBlock.
     */
    public function promptDirectiveFor(string $industry, string $jurisdiction): string
    {
        $rules = $this->activeRulesFor($industry, $jurisdiction);

        return self::renderDirectiveBlock($rules->all(), $industry, $jurisdiction);
    }

    /**
     * Pure renderer — no DB, no cache — so it is unit-testable without a
     * database and is the single source of truth for the prompt block shape.
     *
     * @param  array<int, ComplianceLegalRule>  $rules
     */
    public static function renderDirectiveBlock(array $rules, string $industry, string $jurisdiction): string
    {
        if ($rules === []) {
            return '';
        }

        $lines = [];
        foreach ($rules as $rule) {
            $tag = $rule->severity === 'block' ? 'MUST' : 'SHOULD';
            $line = "- [{$tag}] {$rule->directive}";
            if (! empty($rule->source)) {
                $line .= " (source: {$rule->source})";
            }
            $lines[] = $line;
        }

        $industryLabel = \App\Support\Compliance\IndustryCatalog::label($industry);

        return "# Legal & advertising-standards rules — DO NOT VIOLATE\n\n"
            ."These apply to a {$industryLabel} business operating in jurisdiction {$jurisdiction}. "
            ."[MUST] rules are hard constraints — a post that breaks one is held by compliance and never publishes. "
            ."[SHOULD] rules are strong advisories. Rewrite any claim that would break a rule rather than break it; "
            ."if a topic cannot be expressed lawfully, choose a different angle.\n\n"
            .implode("\n", $lines)
            ."\n";
    }

    /** Bust the cached rule list after an operator edits the rules. */
    public function bustCache(string $industry, string $jurisdiction): void
    {
        $industry = $industry !== '' ? $industry : ComplianceLegalRule::WILDCARD;
        $jurisdiction = $jurisdiction !== '' ? $jurisdiction : ComplianceLegalRule::WILDCARD;
        Cache::forget(sprintf('legal_rules:%s:%s', $industry, $jurisdiction));
    }
}
