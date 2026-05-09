<?php

namespace Tests\Unit;

use App\Agents\Prompts\ResearcherPrompt;
use Tests\TestCase;

class ResearcherPromptTest extends TestCase
{
    public function test_version_is_locked(): void
    {
        $this->assertSame('researcher.v1.0', ResearcherPrompt::VERSION);
    }

    public function test_system_prompt_demands_grounded_evidence(): void
    {
        $prompt = ResearcherPrompt::system();

        $this->assertStringContainsString('grounded in the SUPPLIED EVIDENCE', $prompt);
        $this->assertStringContainsString('5 angles', $prompt);
        $this->assertStringContainsString('Do not invent', $prompt);
    }

    public function test_schema_requires_five_core_fields_per_angle(): void
    {
        $schema = ResearcherPrompt::schema();

        $this->assertSame('object', $schema['type']);
        $this->assertContains('angles', $schema['required']);

        $angle = $schema['properties']['angles']['items'];
        $required = $angle['required'];

        foreach (['hook', 'thesis', 'evidence', 'tension', 'audience'] as $field) {
            $this->assertContains($field, $required, "angle.required must include {$field}");
        }
    }

    public function test_source_ids_are_optional_and_typed_int(): void
    {
        $schema = ResearcherPrompt::schema();
        $angle = $schema['properties']['angles']['items'];

        $this->assertArrayHasKey('source_ids', $angle['properties']);
        $this->assertSame('array', $angle['properties']['source_ids']['type']);
        $this->assertSame('integer', $angle['properties']['source_ids']['items']['type']);

        // Must NOT be in required — empty corpus = empty source_ids array.
        $this->assertNotContains('source_ids', $angle['required']);
    }
}
