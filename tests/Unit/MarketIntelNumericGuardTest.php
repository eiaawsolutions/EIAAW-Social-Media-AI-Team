<?php

namespace Tests\Unit;

use App\Agents\MarketIntelAgent;
use App\Agents\Prompts\MarketIntelPrompt;
use Tests\TestCase;

/**
 * P2 fix — filterTrendsByEvidence validated only the citation IDs, not the
 * narrative. A trend could assert "65% growth" in why_relevant / suggested_angle
 * even when no cited signal contained that figure. This adds a numeric-claim
 * guard: a number in the narrative must appear in at least one cited signal's
 * text, else the trend is dropped (matching the discard-on-bad-citation rule).
 * Pure (no DB).
 */
class MarketIntelNumericGuardTest extends TestCase
{
    public function test_version_bumped(): void
    {
        $this->assertSame('market_intel.v1.1', MarketIntelPrompt::VERSION);
    }

    public function test_numeric_claims_grounded_helper(): void
    {
        // Numbers present in the evidence pass.
        $this->assertTrue(MarketIntelAgent::numericClaimsGrounded(
            'Searches rose 40% this quarter', 'Report: a 40% rise in branded search'
        ));
        // A narrative with no numbers is trivially grounded.
        $this->assertTrue(MarketIntelAgent::numericClaimsGrounded(
            'Demand is shifting toward sustainability', 'Anything here'
        ));
        // A number absent from the evidence fails.
        $this->assertFalse(MarketIntelAgent::numericClaimsGrounded(
            'Market grew 65% year on year', 'No figures appear in this snippet at all'
        ));
        // Percent sign / decimals tolerated; 12 is grounded by "12".
        $this->assertTrue(MarketIntelAgent::numericClaimsGrounded(
            'Up 12 points', 'rose by 12 points last month'
        ));
    }

    public function test_filter_drops_trend_with_ungrounded_number(): void
    {
        $allowedIds = [10, 11];
        $signalTexts = [
            10 => 'Sustainability demand is rising across the category',
            11 => 'Branded search up 40% after the campaign',
        ];

        $trends = [
            // Grounded: 40% appears in signal 11.
            ['trend' => 'Branded search lift', 'evidence_signal_ids' => [11],
             'why_relevant' => 'Branded search rose 40% — ride the wave', 'suggested_angle' => 'Show the 40% lift'],
            // Ungrounded: 65% appears in NO cited signal → dropped.
            ['trend' => 'Explosive growth', 'evidence_signal_ids' => [10],
             'why_relevant' => 'The category is growing 65% a year', 'suggested_angle' => 'Cite the 65% boom'],
        ];

        $out = MarketIntelAgent::filterTrendsByEvidence($trends, $allowedIds, $signalTexts);

        $names = array_column($out, 'trend');
        $this->assertContains('Branded search lift', $names);
        $this->assertNotContains('Explosive growth', $names);
    }

    public function test_filter_unchanged_when_no_signal_texts_supplied(): void
    {
        // Backward-compatible: with no signalTexts map, the numeric guard is a
        // no-op and only citation-ID validation runs (existing behaviour).
        $out = MarketIntelAgent::filterTrendsByEvidence(
            [['trend' => 'T', 'evidence_signal_ids' => [1], 'why_relevant' => 'grew 99%', 'suggested_angle' => 'x']],
            [1],
        );
        $this->assertCount(1, $out);
    }
}
