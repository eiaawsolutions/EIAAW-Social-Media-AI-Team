<?php

namespace Tests\Unit;

use App\Agents\StrategistAgent;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for the Strategist's two new brief renderers.
 * The critical guarantee: an empty brief renders to '' so the prompt section
 * is suppressed entirely — keeping an un-enriched brand's prompt byte-identical
 * to the pre-feature behaviour.
 */
class StrategistBriefBlockRenderingTest extends TestCase
{
    public function test_competitor_strategy_block_suppresses_when_all_empty(): void
    {
        $this->assertSame('', StrategistAgent::renderCompetitorStrategyBlock([], [], [], []));
    }

    public function test_competitor_strategy_block_renders_themes_sov_positioning_whitespace(): void
    {
        $block = StrategistAgent::renderCompetitorStrategyBlock(
            themes: [['theme' => 'Sustainability', 'competitors' => ['Acme Corp']]],
            shareOfVoice: ['Acme Corp' => 75.0, 'Beta Co' => 25.0],
            positioning: [['competitor_label' => 'Acme Corp', 'positioning_summary' => 'Premium, price-led']],
            whitespace: ['Local sourcing stories'],
        );

        $this->assertStringContainsString('# Competitor strategy synthesis (last 30 days)', $block);
        $this->assertStringContainsString('Sustainability', $block);
        $this->assertStringContainsString('Acme Corp 75%', $block);
        $this->assertStringContainsString('Premium, price-led', $block);
        $this->assertStringContainsString('WHITESPACE', $block);
        $this->assertStringContainsString('Local sourcing stories', $block);
        // Truthfulness guard carried into the prompt.
        $this->assertStringContainsString('Never name competitors or claim their metrics', $block);
    }

    public function test_competitor_strategy_block_skips_blank_rows(): void
    {
        // Themes with empty names / positioning missing a summary must not
        // produce stray bullet lines — and if everything is blank, suppress.
        $block = StrategistAgent::renderCompetitorStrategyBlock(
            themes: [['theme' => '   ', 'competitors' => []]],
            shareOfVoice: [],
            positioning: [['competitor_label' => 'X', 'positioning_summary' => '']],
            whitespace: [],
        );

        $this->assertSame('', $block);
    }

    public function test_market_trend_block_suppresses_when_all_empty(): void
    {
        $this->assertSame('', StrategistAgent::renderMarketTrendBlock('', [], []));
    }

    public function test_market_trend_block_renders_summary_trends_seasonal(): void
    {
        $block = StrategistAgent::renderMarketTrendBlock(
            marketSummary: 'Specialty coffee demand is rising among urban professionals.',
            trends: [[
                'trend' => 'Single-origin transparency',
                'evidence_signal_ids' => [10],
                'why_relevant' => 'your audience values provenance',
                'suggested_angle' => 'show the farm-to-cup chain',
            ]],
            seasonal: [[
                'moment' => 'Ramadan',
                'window' => 'March 2026',
                'why_relevant' => 'evening foot traffic spikes',
            ]],
        );

        $this->assertStringContainsString('# Market & Trend brief (verified signals)', $block);
        $this->assertStringContainsString('Specialty coffee demand is rising', $block);
        $this->assertStringContainsString('Single-origin transparency', $block);
        $this->assertStringContainsString('angle: show the farm-to-cup chain', $block);
        $this->assertStringContainsString('Ramadan', $block);
        // Truthfulness guard carried into the prompt.
        $this->assertStringContainsString('Never assert a market statistic the brief did not supply', $block);
    }

    public function test_market_trend_block_renders_with_only_summary(): void
    {
        $block = StrategistAgent::renderMarketTrendBlock('Market is steady.', [], []);

        $this->assertStringContainsString('# Market & Trend brief', $block);
        $this->assertStringContainsString('Market is steady.', $block);
    }
}
