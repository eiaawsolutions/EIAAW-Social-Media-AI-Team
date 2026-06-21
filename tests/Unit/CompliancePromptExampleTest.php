<?php

namespace Tests\Unit;

use App\Agents\Prompts\ComplianceLegalPrompt;
use App\Agents\Prompts\ComplianceVoicePrompt;
use Tests\TestCase;

/**
 * P1 fix — the two Compliance LLM-judge prompts carried zero worked examples and
 * did not document the user-message structure they receive. An LLM-as-judge
 * calibrating a 0.70/0.80 threshold benefits most from a worked case. This locks
 * in (a) a documented input contract and (b) at least one worked example in each
 * prompt, and bumps both versions. DB-free (pure prompt strings).
 */
class CompliancePromptExampleTest extends TestCase
{
    public function test_voice_version_bumped(): void
    {
        $this->assertSame('compliance.voice.v1.1', ComplianceVoicePrompt::VERSION);
    }

    public function test_legal_version_bumped(): void
    {
        // v1.2 — first-party-feature carve-out (see LegalFirstPartyFeatureCarveOutTest).
        $this->assertSame('compliance.legal.v1.2', ComplianceLegalPrompt::VERSION);
    }

    public function test_voice_prompt_documents_input_contract(): void
    {
        $s = ComplianceVoicePrompt::system();
        // The judge must know it receives the brand-style header + DRAFT TO SCORE.
        $this->assertStringContainsString('brand-style.md', $s);
        $this->assertStringContainsString('DRAFT TO SCORE', $s);
    }

    public function test_voice_prompt_has_worked_example(): void
    {
        $s = ComplianceVoicePrompt::system();
        $this->assertStringContainsString('# Example', $s);
        // Example must show a concrete tone_score/audience_score read.
        $this->assertStringContainsString('tone_score', $s);
        $this->assertStringContainsString('audience_score', $s);
    }

    public function test_legal_prompt_documents_input_contract(): void
    {
        $s = ComplianceLegalPrompt::system();
        $this->assertStringContainsString('INDUSTRY', $s);
        $this->assertStringContainsString('JURISDICTION', $s);
        $this->assertStringContainsString('DRAFT_BODY', $s);
    }

    public function test_legal_prompt_has_worked_example(): void
    {
        $s = ComplianceLegalPrompt::system();
        $this->assertStringContainsString('# Example', $s);
        // Example must show a concrete violation shape (verdict + a violation).
        $this->assertStringContainsString('"verdict"', $s);
    }
}
