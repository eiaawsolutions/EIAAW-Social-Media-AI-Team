<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Support\Compliance\JurisdictionResolver;
use Tests\TestCase;

/**
 * DB-free tests for the Brand accessors that feed the legal-compliance lookup.
 * Uses unsaved Brand instances (the array cast on business_locations works
 * without a database; the suite runs DB-free).
 */
class BrandLegalDerivationTest extends TestCase
{
    public function test_primary_jurisdiction_derives_from_primary_business_location(): void
    {
        $brand = new Brand();
        $brand->business_locations = [
            ['area' => 'Singapore', 'country' => 'Singapore', 'is_primary' => false],
            ['area' => 'Kuala Lumpur', 'country' => 'Malaysia', 'is_primary' => true],
        ];

        $this->assertSame('MY', $brand->primaryJurisdiction());
    }

    public function test_primary_jurisdiction_falls_back_to_default_when_no_locations(): void
    {
        $brand = new Brand();

        $this->assertSame(JurisdictionResolver::DEFAULT, $brand->primaryJurisdiction());
    }

    public function test_industry_key_normalises_free_text(): void
    {
        $brand = new Brand();
        $brand->industry = 'F&B';
        $this->assertSame('food_beverage', $brand->industryKey());

        $brand->industry = 'financial_services';
        $this->assertSame('financial_services', $brand->industryKey());

        $brand->industry = null;
        $this->assertSame('other', $brand->industryKey());
    }
}
