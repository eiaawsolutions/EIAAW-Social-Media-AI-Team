<?php

namespace Tests\Unit;

use App\Agents\Prompts\StrategistPrompt;
use Tests\TestCase;

class StrategistPromptTest extends TestCase
{
    public function test_version_bumped_for_competitor_awareness(): void
    {
        // v1.1 added Competitor signals block. Bump must be visible so the
        // optimizer treats prior calendars as a different prompt-version
        // input cohort.
        $this->assertSame('strategist.v1.1', StrategistPrompt::VERSION);
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
