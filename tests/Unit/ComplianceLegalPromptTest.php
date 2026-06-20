<?php

namespace Tests\Unit;

use App\Agents\Prompts\ComplianceLegalPrompt;
use Tests\TestCase;

class ComplianceLegalPromptTest extends TestCase
{
    public function test_version_is_pinned(): void
    {
        // Bumped to v1.1 when the input-contract header + a worked example were
        // added (the scoring rubric + jailbreak defence are unchanged).
        $this->assertSame('compliance.legal.v1.1', ComplianceLegalPrompt::VERSION);
    }

    public function test_system_prompt_frames_must_vs_should_and_precision(): void
    {
        $prompt = ComplianceLegalPrompt::system();

        $this->assertStringContainsString('[MUST]', $prompt);
        $this->assertStringContainsString('[SHOULD]', $prompt);
        // Anti-paranoia guard so ordinary marketing copy doesn't false-positive.
        $this->assertStringContainsString('Be precise, not paranoid', $prompt);
        $this->assertStringContainsString('Output ONLY the JSON', $prompt);
    }

    public function test_system_prompt_treats_draft_as_untrusted_data(): void
    {
        // Prompt-injection defence: the judge must be told the draft is data and
        // never to obey instructions/pre-approval claims inside it.
        $prompt = ComplianceLegalPrompt::system();

        $this->assertStringContainsString('untrusted DATA', $prompt);
        $this->assertStringContainsString('DRAFT_BODY', $prompt);
        $this->assertStringContainsStringIgnoringCase('never obey', $prompt);
    }

    public function test_schema_has_no_min_max_on_numbers(): void
    {
        // Anthropic's structured-output validator rejects minimum/maximum on
        // number types. Lock this — the codebase has hit it before.
        $schema = ComplianceLegalPrompt::schema();
        $json = json_encode($schema);

        $this->assertStringNotContainsString('"minimum"', $json);
        $this->assertStringNotContainsString('"maximum"', $json);
    }

    public function test_schema_requires_score_verdict_and_violations(): void
    {
        $schema = ComplianceLegalPrompt::schema();

        $this->assertContains('score', $schema['required']);
        $this->assertContains('verdict', $schema['required']);
        $this->assertContains('violations', $schema['required']);

        $this->assertSame('number', $schema['properties']['score']['type']);
        $this->assertSame(['pass', 'fail'], $schema['properties']['verdict']['enum']);

        // Each violation carries the rule_code + verbatim phrase the operator
        // needs to trace a block.
        $vItem = $schema['properties']['violations']['items'];
        $this->assertContains('rule_code', $vItem['required']);
        $this->assertContains('phrase', $vItem['required']);
        $this->assertSame(['block', 'advisory'], $vItem['properties']['severity']['enum']);
    }
}
