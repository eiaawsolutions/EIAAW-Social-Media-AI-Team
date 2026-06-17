<?php

namespace App\Support\Compliance;

/**
 * The curated industry vocabulary. A single source of truth for:
 *   - the industry <Select> on every brand-edit surface (Agency BrandResource,
 *     HQ ClientBrandResource, and the brand-corpus business-facts page), and
 *   - the `industry` key on compliance_legal_rules.
 *
 * Brands store the KEY (e.g. 'financial_services'); the label is display-only.
 * Keeping this list closed (not free text) is what lets a brand's industry line
 * up deterministically with a seeded rule set. 'other' is the escape hatch for
 * verticals we haven't curated rules for yet — it carries no industry-specific
 * legal rules (only the global '*' ad-standards apply), so it never hard-blocks
 * on an industry rule the operator can't see.
 */
final class IndustryCatalog
{
    /** Escape-hatch key for un-curated verticals. */
    public const OTHER = 'other';

    /**
     * @return array<string, string> key => human label (drives Select options)
     */
    public static function industries(): array
    {
        return [
            'food_beverage' => 'Food & Beverage',
            'healthcare' => 'Healthcare & Medical',
            'beauty_cosmetics' => 'Beauty & Cosmetics',
            'financial_services' => 'Financial Services',
            'retail_ecommerce' => 'Retail & E-commerce',
            'professional_services' => 'Professional Services',
            'technology_saas' => 'Technology / SaaS',
            'education' => 'Education & Training',
            'real_estate' => 'Real Estate & Property',
            'travel_hospitality' => 'Travel & Hospitality',
            'fitness_wellness' => 'Fitness & Wellness',
            self::OTHER => 'Other',
        ];
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::industries());
    }

    public static function label(?string $key): string
    {
        return self::industries()[$key] ?? 'Other';
    }

    /**
     * Normalise a stored/free-text industry to a catalog key. Existing brands
     * have free-text values ('SaaS', 'F&B', 'Healthcare', …) from before the
     * Select; map the common ones so legacy rows still light up the right rules
     * without a data migration. Unknown input collapses to 'other'.
     */
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return self::OTHER;
        }

        // Already a canonical key.
        if (array_key_exists($value, self::industries())) {
            return $value;
        }

        $needle = mb_strtolower($value);

        // Most-SPECIFIC verticals are listed before broad ones so a multi-signal
        // string resolves to the narrower, more legally-load-bearing vertical
        // (e.g. "Health food store" -> healthcare beats food_beverage; "Beauty
        // clinic" -> healthcare's clinic is intentionally AFTER beauty here so
        // beauty wins for a beauty business). Aliases are matched as WORD
        // PREFIXES (word-start boundary, no trailing boundary) so "pharma" still
        // catches "pharmacy" while "it" can no longer match inside "hospital".
        // Dangerous ultra-short/substring-prone aliases ("it", "app", "law")
        // were removed or made word-anchored.
        $aliases = [
            'beauty_cosmetics' => ['beauty', 'cosmetic', 'skincare', 'makeup', 'salon', 'aesthetic'],
            'healthcare' => ['health', 'medical', 'medicine', 'clinic', 'pharma', 'dental', 'hospital', 'doctor', 'therapy'],
            'financial_services' => ['finance', 'financial', 'fintech', 'bank', 'insurance', 'investment', 'wealth', 'lending'],
            'education' => ['education', 'training', 'school', 'university', 'academy', 'edtech', 'tuition', 'course', 'tutor'],
            'real_estate' => ['real estate', 'property', 'realty', 'realtor', 'developer'],
            'travel_hospitality' => ['travel', 'hospitality', 'hotel', 'tourism', 'resort', 'airline'],
            'fitness_wellness' => ['fitness', 'gym', 'yoga', 'pilates', 'wellness', 'sport'],
            'food_beverage' => ['f&b', 'fnb', 'food', 'beverage', 'cafe', 'café', 'restaurant', 'coffee', 'bakery'],
            'retail_ecommerce' => ['retail', 'ecommerce', 'e-commerce', 'marketplace', 'merchant'],
            'professional_services' => ['consulting', 'consultancy', 'legal', 'lawyer', 'accounting', 'audit', 'advisory'],
            'technology_saas' => ['saas', 'software', 'technology', 'platform', 'startup'],
        ];

        // Pass 1: word-prefix match (boundary before the alias). Catches
        // "pharmacy" via "pharma", "fintech company" via "fintech", without the
        // substring collisions of the old str_contains.
        foreach ($aliases as $key => $needles) {
            foreach ($needles as $alias) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'/u', $needle) === 1) {
                    return $key;
                }
            }
        }

        return self::OTHER;
    }
}
