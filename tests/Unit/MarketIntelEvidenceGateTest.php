<?php

namespace Tests\Unit;

use App\Agents\MarketIntelAgent;
use App\Models\Brand;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for MarketIntelAgent's verification + query
 * helpers. These lock the post-synthesis evidence gate: a trend survives only
 * if it cites a REAL signal id; hallucinated citations are dropped.
 */
class MarketIntelEvidenceGateTest extends TestCase
{
    public function test_trend_citing_unknown_signal_id_is_dropped(): void
    {
        $trends = [
            ['trend' => 'Real trend', 'evidence_signal_ids' => [10, 11], 'why_relevant' => 'x', 'suggested_angle' => 'y'],
            ['trend' => 'Hallucinated trend', 'evidence_signal_ids' => [999], 'why_relevant' => 'x', 'suggested_angle' => 'y'],
        ];

        $kept = MarketIntelAgent::filterTrendsByEvidence($trends, [10, 11, 12]);

        $this->assertCount(1, $kept);
        $this->assertSame('Real trend', $kept[0]['trend']);
        $this->assertSame([10, 11], $kept[0]['evidence_signal_ids']);
    }

    public function test_trend_with_mixed_real_and_fake_ids_keeps_only_real(): void
    {
        $trends = [
            ['trend' => 'Mixed', 'evidence_signal_ids' => [10, 999, 11], 'why_relevant' => '', 'suggested_angle' => ''],
        ];

        $kept = MarketIntelAgent::filterTrendsByEvidence($trends, [10, 11]);

        $this->assertCount(1, $kept);
        $this->assertSame([10, 11], $kept[0]['evidence_signal_ids']);
    }

    public function test_trend_with_no_valid_ids_is_dropped(): void
    {
        $trends = [
            ['trend' => 'Uncited', 'evidence_signal_ids' => [], 'why_relevant' => '', 'suggested_angle' => ''],
            ['trend' => 'All fake', 'evidence_signal_ids' => [900, 901], 'why_relevant' => '', 'suggested_angle' => ''],
        ];

        $kept = MarketIntelAgent::filterTrendsByEvidence($trends, [1, 2, 3]);

        $this->assertSame([], $kept);
    }

    public function test_trend_with_empty_name_is_dropped(): void
    {
        $trends = [
            ['trend' => '   ', 'evidence_signal_ids' => [1], 'why_relevant' => '', 'suggested_angle' => ''],
        ];

        $this->assertSame([], MarketIntelAgent::filterTrendsByEvidence($trends, [1]));
    }

    public function test_count_cited_signals_is_distinct(): void
    {
        $trends = [
            ['trend' => 'A', 'evidence_signal_ids' => [1, 2]],
            ['trend' => 'B', 'evidence_signal_ids' => [2, 3]],
        ];

        // distinct ids: 1, 2, 3
        $this->assertSame(3, MarketIntelAgent::countCitedSignals($trends));
    }

    public function test_build_queries_uses_industry_and_geo_and_caps_count(): void
    {
        $queries = MarketIntelAgent::buildQueries('specialty coffee', ['Malaysia'], 3);

        $this->assertCount(3, $queries);
        $this->assertStringContainsString('specialty coffee', $queries[0]['query']);
        $this->assertStringContainsString('Malaysia', $queries[0]['query']);
        // Each query carries a signal_class tag.
        foreach ($queries as $q) {
            $this->assertArrayHasKey('class', $q);
            $this->assertArrayHasKey('query', $q);
        }
    }

    public function test_build_queries_empty_industry_returns_nothing(): void
    {
        $this->assertSame([], MarketIntelAgent::buildQueries('', ['Malaysia'], 6));
    }

    public function test_build_queries_without_geo_still_produces_queries(): void
    {
        $queries = MarketIntelAgent::buildQueries('fintech', [], 4);

        $this->assertCount(4, $queries);
        $this->assertStringContainsString('fintech', $queries[0]['query']);
    }

    public function test_derive_geo_terms_prefers_geo_focus_then_primary_country(): void
    {
        $brand = new Brand([
            'business_locations' => [
                ['area' => 'Penang', 'country' => 'Malaysia', 'is_primary' => false],
                ['area' => 'Kuala Lumpur', 'country' => 'Malaysia', 'is_primary' => true],
            ],
            'audience_profile' => ['geo_focus' => 'Klang Valley, Malaysia'],
        ]);

        $terms = MarketIntelAgent::deriveGeoTerms($brand);

        // geo_focus (most specific, operator-authored) comes first.
        $this->assertSame('Klang Valley, Malaysia', $terms[0]);
        $this->assertContains('Malaysia', $terms);
    }

    public function test_derive_geo_terms_empty_when_no_facts(): void
    {
        $brand = new Brand([]);
        $this->assertSame([], MarketIntelAgent::deriveGeoTerms($brand));
    }
}
