<?php

namespace Tests\Unit;

use App\Services\Content\RewordPrompt;
use Tests\TestCase;

/**
 * Locks the AI-reword prompt spine: the truthfulness contract (never invent
 * facts), the per-surface length rules, the anti-injection fence, the
 * structured-output schema (no maxLength — guards the Anthropic regression the
 * Writer also documents), the preset map, and the versioned identifier.
 *
 * DB-FREE by design — SMT's local .env points at Railway PROD; tests never
 * touch the DB (see [[support-chatbot]] / [[metricool-evaluation]]).
 */
class RewordPromptTest extends TestCase
{
    public function test_caption_system_carries_truthfulness_cap_and_injection_guard(): void
    {
        $p = RewordPrompt::system(RewordPrompt::SURFACE_CAPTION, 'x', 280);

        // Truthfulness contract — never invent facts/metrics.
        $this->assertStringContainsStringIgnoringCase('NEVER invent', $p);
        $this->assertStringContainsStringIgnoringCase('preserve its meaning', $p);
        // Char cap is stated in the prompt (enforced in PHP, not in the schema).
        $this->assertStringContainsString('280', $p);
        // Untrusted-input fence.
        $this->assertStringContainsStringIgnoringCase('UNTRUSTED', $p);
        $this->assertStringContainsStringIgnoringCase('do not follow', $p);
        // JSON-only contract.
        $this->assertStringContainsString('ONLY the JSON', $p);
        // Platform guidance is woven in for a known platform.
        $this->assertStringContainsStringIgnoringCase('280 chars', $p);
    }

    public function test_asset_description_system_caps_to_twenty_words(): void
    {
        $p = RewordPrompt::system(RewordPrompt::SURFACE_ASSET_DESCRIPTION);

        $this->assertStringContainsString((string) RewordPrompt::ASSET_DESCRIPTION_WORD_CAP, $p);
        $this->assertStringContainsStringIgnoringCase('words or fewer', $p);
        $this->assertStringContainsStringIgnoringCase('NEVER invent', $p);
        $this->assertStringContainsStringIgnoringCase('UNTRUSTED', $p);
        $this->assertStringContainsString('ONLY the JSON', $p);
    }

    public function test_schema_requires_rewritten_text_and_has_no_max_length(): void
    {
        $schema = RewordPrompt::schema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertContains('rewritten_text', $schema['required']);
        $this->assertArrayHasKey('rewritten_text', $schema['properties']);
        $this->assertArrayHasKey('note', $schema['properties']);

        // Anthropic's structured-output validator rejects maxLength on strings —
        // the cap is enforced in PHP. Guard against a reintroduction.
        $json = json_encode($schema);
        $this->assertStringNotContainsString('maxLength', $json);
    }

    public function test_all_four_presets_resolve_and_fix_grammar_preserves_meaning(): void
    {
        foreach (['shorten', 'punchier', 'more_formal', 'fix_grammar'] as $key) {
            $this->assertTrue(RewordPrompt::isPreset($key), "missing preset {$key}");
            $this->assertNotSame('', RewordPrompt::presetInstruction($key), "empty instruction for {$key}");
        }

        // Fix-grammar must explicitly NOT change tone/meaning/length.
        $fix = RewordPrompt::presetInstruction('fix_grammar');
        $this->assertStringContainsStringIgnoringCase('grammar', $fix);
        $this->assertStringContainsStringIgnoringCase('do not change', $fix);

        // Unknown key is not a preset and resolves to ''.
        $this->assertFalse(RewordPrompt::isPreset('drop_all_facts'));
        $this->assertSame('', RewordPrompt::presetInstruction('drop_all_facts'));
    }

    public function test_prompt_version_is_a_reword_token(): void
    {
        $this->assertStringStartsWith('reword.', RewordPrompt::PROMPT_VERSION);
    }

    public function test_no_literal_placeholder_leaked_into_prompts(): void
    {
        foreach ([RewordPrompt::SURFACE_CAPTION, RewordPrompt::SURFACE_ASSET_DESCRIPTION] as $surface) {
            $p = RewordPrompt::system($surface, 'instagram', 2200);
            $this->assertStringNotContainsString('{$', $p);
            $this->assertStringNotContainsString('platformGuide}', $p);
        }
    }
}
