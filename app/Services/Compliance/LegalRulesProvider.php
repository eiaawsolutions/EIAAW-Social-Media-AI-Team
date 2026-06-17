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
 * Output is cached 60s per (industry, jurisdiction), versioned by a global
 * stamp so an operator rule edit (or re-seed) invalidates every entry at once
 * via flush(); absent an edit, entries self-heal after the 60s TTL.
 */
class LegalRulesProvider
{
    private const CACHE_TTL_SECONDS = 60;

    /** Hard cap — these go into LLM prompts, so prompt size matters. */
    private const MAX_RULES = 20;

    /**
     * Version stamp folded into every cache key. Bumping it (flush()) atomically
     * invalidates EVERY (industry, jurisdiction) entry in one write — the only
     * correct invalidation when a wildcard ('*') rule changes, since a single
     * global row participates in every derived key and a per-key forget can't
     * reach them all. Operator edits (Filament resource) and re-seeds call flush().
     */
    private const VERSION_KEY = 'legal_rules:version';

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
        $version = (int) Cache::get(self::VERSION_KEY, 0);
        $key = sprintf('legal_rules:v%d:%s:%s', $version, $industry, $jurisdiction);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($industry, $jurisdiction) {
            $wildcard = ComplianceLegalRule::WILDCARD;

            // Block-severity rules FIRST, then by rule_code — so the MAX_RULES
            // cap never silently drops a [MUST] rule behind an advisory one.
            // (NB: a bare orderBy('severity') sorts 'advisory' before 'block'
            // alphabetically, which is the WRONG order — hence the explicit CASE.)
            return ComplianceLegalRule::query()
                ->where('disabled', false)
                ->whereIn('industry', array_unique([$industry, $wildcard]))
                ->whereIn('jurisdiction', array_unique([$jurisdiction, $wildcard]))
                ->orderByRaw("CASE WHEN severity = 'block' THEN 0 ELSE 1 END")
                ->orderBy('rule_code')
                ->limit(self::MAX_RULES)
                ->get();
        });
    }

    /**
     * Pure, DB-free ordering used by the query above and verifiable in tests:
     * block-severity rules first, then by rule_code. Guarantees the MAX_RULES
     * cap (applied after this order) never truncates a [MUST] rule ahead of an
     * advisory one.
     *
     * @param  array<int, ComplianceLegalRule>  $rules
     * @return array<int, ComplianceLegalRule>
     */
    public static function sortBlockFirst(array $rules): array
    {
        usort($rules, function ($a, $b) {
            $aBlock = $a->severity === 'block' ? 0 : 1;
            $bBlock = $b->severity === 'block' ? 0 : 1;
            if ($aBlock !== $bBlock) {
                return $aBlock <=> $bBlock;
            }

            return strcmp((string) $a->rule_code, (string) $b->rule_code);
        });

        return $rules;
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
        $rules = self::sortBlockFirst($this->activeRulesFor($industry, $jurisdiction)->all());

        return self::renderDirectiveBlock($rules, $industry, $jurisdiction);
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

    /**
     * Invalidate ALL cached rule lists in one write by bumping the version
     * stamp. Call after any rule create/edit/toggle/delete (operator Filament
     * resource, seeder, CLI). Correct under wildcard rows + the DB/array cache
     * store SMT uses (where cache tags aren't available).
     */
    public function flush(): void
    {
        Cache::increment(self::VERSION_KEY);
        // increment() is a no-op when the key is absent on some stores; ensure
        // a definite bump by seeding then incrementing if it didn't take.
        if (Cache::get(self::VERSION_KEY) === null) {
            Cache::forever(self::VERSION_KEY, 1);
        }
    }
}
