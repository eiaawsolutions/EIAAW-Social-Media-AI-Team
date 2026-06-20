<?php

namespace Tests\Unit;

use App\Agents\Prompts\CompetitorStrategistPrompt;
use App\Agents\Prompts\MarketIntelPrompt;
use Tests\TestCase;

/**
 * Locks the version + the truthfulness guards on the two new synthesis prompts.
 */
class StrategyBriefingPromptsTest extends TestCase
{
    public function test_competitor_strategist_prompt_version_and_guards(): void
    {
        $this->assertSame('competitor_strategist.v1.0', CompetitorStrategistPrompt::VERSION);

        $system = CompetitorStrategistPrompt::system();
        // Must forbid inventing metrics (the ad library exposes none).
        $this->assertStringContainsString('NEVER state or estimate spend', $system);
        $this->assertStringContainsStringIgnoringCase('whitespace', $system);
        // Must NOT ask the model for share-of-voice — the agent computes it.
        $this->assertStringContainsString('Do NOT output share-of-voice', $system);

        $schema = CompetitorStrategistPrompt::schema();
        $this->assertArrayHasKey('dominant_themes', $schema['properties']);
        $this->assertArrayHasKey('positioning_map', $schema['properties']);
        $this->assertArrayHasKey('whitespace', $schema['properties']);
        // share_of_voice is NOT in the model's schema — it's computed in PHP.
        $this->assertArrayNotHasKey('share_of_voice', $schema['properties']);
    }

    public function test_market_intel_prompt_version_and_cited_evidence_rule(): void
    {
        $this->assertSame('market_intel.v1.1', MarketIntelPrompt::VERSION);

        $system = MarketIntelPrompt::system();
        // Every trend must cite a supplied signal id.
        $this->assertStringContainsString('MUST cite at least one signal [id]', $system);
        // No invented statistics.
        $this->assertStringContainsString('NEVER invent a statistic', $system);

        $schema = MarketIntelPrompt::schema();
        $trendProps = $schema['properties']['trends']['items']['properties'];
        $this->assertArrayHasKey('evidence_signal_ids', $trendProps);
        // evidence is required on every trend.
        $this->assertContains('evidence_signal_ids', $schema['properties']['trends']['items']['required']);
    }
}
