<?php

namespace Tests\Unit;

use App\Agents\Prompts\StrategistPrompt;
use Tests\TestCase;

class StrategistPromptTest extends TestCase
{
    public function test_version_bumped_for_strategy_briefing(): void
    {
        // v1.1 added Competitor signals; v1.2 the creative-director enrichment;
        // v1.5 the Strategy Briefing (competitor-strategy synthesis + market &
        // trend brief); v1.6 the Growth strategy block. The bump must be visible
        // so the optimizer treats prior calendars as a different prompt-version
        // input cohort.
        $this->assertSame('strategist.v1.6', StrategistPrompt::VERSION);
    }

    public function test_system_prompt_includes_competitor_strategy_and_market_trend_sections(): void
    {
        $prompt = StrategistPrompt::system();

        // Dim 2 — competitor strategy synthesis + whitespace targeting.
        $this->assertStringContainsString('Competitor strategy synthesis', $prompt);
        $this->assertStringContainsStringIgnoringCase('whitespace', $prompt);

        // Dim 1+3 — market & trend brief + the no-fabrication guard.
        $this->assertStringContainsString('Market & Trend brief', $prompt);
        $this->assertStringContainsString('NEVER assert a market statistic', $prompt);
    }

    public function test_system_prompt_includes_hook_framework_and_creative_fields(): void
    {
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('Hook framework', $prompt);
        $this->assertStringContainsString('target_emotion', $prompt);
        $this->assertStringContainsString('content_angle', $prompt);
        // Still emits only the publish-safe format enum, never invented strings.
        $this->assertStringContainsString('Do NOT invent new format strings', $prompt);
    }

    public function test_schema_exposes_target_emotion_and_content_angle(): void
    {
        $props = StrategistPrompt::schema()['properties']['entries']['items']['properties'];

        $this->assertArrayHasKey('target_emotion', $props);
        $this->assertArrayHasKey('content_angle', $props);
        // They must stay OPTIONAL — older parsing + the agent's persistence
        // both tolerate their absence.
        $required = StrategistPrompt::schema()['properties']['entries']['items']['required'];
        $this->assertNotContains('target_emotion', $required);
        $this->assertNotContains('content_angle', $required);
    }

    public function test_system_prompt_includes_competitor_awareness_section(): void
    {
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('Competitor awareness', $prompt);
        $this->assertStringContainsStringIgnoringCase('counter-positioning', $prompt);
        $this->assertStringContainsString('NEVER claim competitor metrics', $prompt);
    }

    public function test_system_prompt_still_demands_30_entries(): void
    {
        // Regression: don't lose the original calendar planning rules
        // when adding competitor awareness.
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('30 entries', $prompt);
        $this->assertStringContainsString('Output ONLY the JSON document', $prompt);
    }
}
