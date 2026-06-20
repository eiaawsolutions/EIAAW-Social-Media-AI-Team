<?php

namespace Tests\Unit;

use App\Agents\OnboardingAgent;
use App\Agents\Prompts\OnboardingPrompt;
use Tests\TestCase;

/**
 * P1 fix — the Onboarding prompt told the model to return "3-5 voice attribute
 * objects" (an array) while the schema enforced a SINGLE object with four
 * arrays. This locks the prompt text and schema to the SAME shape, adds the
 * evidence-quote floor, and proves the word-count gate rejects an obviously
 * truncated brand-style.md instead of silently accepting it. DB-free.
 */
class OnboardingPromptContractTest extends TestCase
{
    public function test_version_bumped(): void
    {
        $this->assertSame('onboarding.v1.1', OnboardingPrompt::VERSION);
    }

    public function test_voice_attributes_schema_is_a_single_object_of_arrays(): void
    {
        $va = OnboardingPrompt::schema()['properties']['voice_attributes'];
        $this->assertSame('object', $va['type']);
        foreach (['tone', 'audience', 'do', 'dont'] as $key) {
            $this->assertSame('array', $va['properties'][$key]['type']);
        }
    }

    public function test_prompt_no_longer_says_voice_attribute_objects_plural(): void
    {
        $system = OnboardingPrompt::system();
        // The old wording implied an array of objects; the schema enforces one
        // object. The prompt must describe the single-object shape.
        $this->assertStringNotContainsString('voice attribute objects', $system);
        $this->assertStringContainsString('voice_attributes', $system);
    }

    public function test_evidence_quotes_has_a_minimum_cardinality(): void
    {
        $eq = OnboardingPrompt::schema()['properties']['evidence_quotes'];
        // minItems is accepted by the Anthropic validator (0 or 1); enforce a floor.
        $this->assertArrayHasKey('minItems', $eq);
        $this->assertGreaterThanOrEqual(1, $eq['minItems']);
    }

    public function test_word_count_gate_rejects_truncated_document(): void
    {
        // An obviously-truncated 200-word doc is below the floor → not acceptable.
        $short = str_repeat('word ', 200);
        $this->assertFalse(OnboardingAgent::isAcceptableStyleLength($short));

        // A healthy 700-word doc passes.
        $ok = str_repeat('word ', 700);
        $this->assertTrue(OnboardingAgent::isAcceptableStyleLength($ok));
    }
}
