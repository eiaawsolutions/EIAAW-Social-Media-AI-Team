<?php

namespace Tests\Unit;

use App\Support\Compliance\IndustryCatalog;
use App\Support\Compliance\JurisdictionResolver;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for the industry vocabulary and the jurisdiction
 * derivation that together key the legal-compliance rule lookup.
 */
class IndustryCatalogTest extends TestCase
{
    public function test_industries_are_a_closed_keyed_list(): void
    {
        $industries = IndustryCatalog::industries();

        $this->assertArrayHasKey('financial_services', $industries);
        $this->assertArrayHasKey('food_beverage', $industries);
        $this->assertArrayHasKey('other', $industries);
        $this->assertSame('Financial Services', $industries['financial_services']);
    }

    public function test_is_valid_only_accepts_catalog_keys(): void
    {
        $this->assertTrue(IndustryCatalog::isValid('financial_services'));
        $this->assertFalse(IndustryCatalog::isValid('Banking'));
        $this->assertFalse(IndustryCatalog::isValid(null));
        $this->assertFalse(IndustryCatalog::isValid(''));
    }

    public function test_normalize_maps_legacy_free_text_to_canonical_keys(): void
    {
        $this->assertSame('food_beverage', IndustryCatalog::normalize('F&B'));
        $this->assertSame('food_beverage', IndustryCatalog::normalize('Coffee shop / café'));
        $this->assertSame('financial_services', IndustryCatalog::normalize('Fintech'));
        $this->assertSame('technology_saas', IndustryCatalog::normalize('SaaS'));
        $this->assertSame('healthcare', IndustryCatalog::normalize('Medical clinic'));
    }

    public function test_normalize_collapses_unknown_and_empty_to_other(): void
    {
        $this->assertSame('other', IndustryCatalog::normalize('Underwater basket weaving'));
        $this->assertSame('other', IndustryCatalog::normalize(''));
        $this->assertSame('other', IndustryCatalog::normalize(null));
    }

    public function test_normalize_passes_through_a_canonical_key(): void
    {
        $this->assertSame('real_estate', IndustryCatalog::normalize('real_estate'));
    }

    public function test_jurisdiction_resolves_from_primary_location(): void
    {
        $locations = [
            ['area' => 'Singapore CBD', 'country' => 'Singapore', 'is_primary' => false],
            ['area' => 'Kuala Lumpur', 'country' => 'Malaysia', 'is_primary' => true],
        ];

        $this->assertSame('MY', JurisdictionResolver::fromBusinessLocations($locations));
    }

    public function test_jurisdiction_falls_back_to_first_location_then_default(): void
    {
        // No primary flagged → first location with a country wins.
        $this->assertSame('SG', JurisdictionResolver::fromBusinessLocations([
            ['area' => 'Orchard', 'country' => 'Singapore'],
            ['area' => 'KL', 'country' => 'Malaysia'],
        ]));

        // Empty → app default.
        $this->assertSame(JurisdictionResolver::DEFAULT, JurisdictionResolver::fromBusinessLocations([]));
    }

    public function test_country_string_maps_to_jurisdiction_key(): void
    {
        $this->assertSame('MY', JurisdictionResolver::fromCountry('malaysia'));
        $this->assertSame('MY', JurisdictionResolver::fromCountry('MY'));
        $this->assertSame('SG', JurisdictionResolver::fromCountry('Singapore'));
        $this->assertSame('ID', JurisdictionResolver::fromCountry('Indonesia'));
        // Unknown → default (Malaysia/APAC lens).
        $this->assertSame(JurisdictionResolver::DEFAULT, JurisdictionResolver::fromCountry('Narnia'));
    }
}
