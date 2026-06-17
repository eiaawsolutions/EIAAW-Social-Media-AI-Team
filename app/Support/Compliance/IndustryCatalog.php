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

        $aliases = [
            'food_beverage' => ['f&b', 'fnb', 'food', 'beverage', 'cafe', 'café', 'restaurant', 'coffee', 'fmcg food'],
            'healthcare' => ['health', 'medical', 'clinic', 'pharma', 'pharmaceutical', 'dental', 'wellness clinic'],
            'beauty_cosmetics' => ['beauty', 'cosmetic', 'cosmetics', 'skincare', 'makeup', 'salon', 'aesthetics'],
            'financial_services' => ['finance', 'financial', 'fintech', 'bank', 'banking', 'insurance', 'investment', 'wealth'],
            'retail_ecommerce' => ['retail', 'ecommerce', 'e-commerce', 'shop', 'store', 'marketplace'],
            'professional_services' => ['consulting', 'consultancy', 'agency', 'legal', 'law', 'accounting', 'professional'],
            'technology_saas' => ['saas', 'software', 'tech', 'technology', 'it', 'app', 'platform'],
            'education' => ['education', 'training', 'school', 'university', 'academy', 'edtech', 'tuition', 'course'],
            'real_estate' => ['real estate', 'property', 'realty', 'developer'],
            'travel_hospitality' => ['travel', 'hospitality', 'hotel', 'tourism', 'resort', 'airline'],
            'fitness_wellness' => ['fitness', 'gym', 'yoga', 'pilates', 'wellness', 'sports'],
        ];

        foreach ($aliases as $key => $needles) {
            foreach ($needles as $alias) {
                if (str_contains($needle, $alias)) {
                    return $key;
                }
            }
        }

        return self::OTHER;
    }
}
