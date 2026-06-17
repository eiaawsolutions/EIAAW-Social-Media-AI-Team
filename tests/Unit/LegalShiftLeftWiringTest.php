<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Shift-left guard: legal compliance must be applied BEFORE posts are planned /
 * drafted (the user's core requirement), not only at the backstop gate. The
 * full prompt path hits the DB (brief renderers query), so rather than spin up a
 * database this test locks the WIRING at the source level — that the Strategist
 * and Writer resolve the legal directive from LegalRulesProvider and interpolate
 * it into their prompt heredocs. If someone removes the injection, this fails.
 *
 * The directive's CONTENT/shape is covered DB-free by LegalRulesProviderTest;
 * the backstop behaviour is covered by ComplianceAgent. This nails the seam in
 * between: prevention is actually plumbed into both upstream agents.
 */
class LegalShiftLeftWiringTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    public function test_strategist_injects_legal_directive_into_prompt(): void
    {
        $src = $this->source('app/Agents/StrategistAgent.php');

        $this->assertStringContainsString('LegalRulesProvider', $src);
        $this->assertStringContainsString('promptDirectiveFor(', $src);
        // Resolved from the brand's industry + jurisdiction.
        $this->assertStringContainsString('industryKey()', $src);
        $this->assertStringContainsString('primaryJurisdiction()', $src);
        // Interpolated into the user message heredoc.
        $this->assertStringContainsString('{$legalSection}', $src);
    }

    public function test_writer_injects_legal_directive_into_draft_and_redraft_prompts(): void
    {
        $src = $this->source('app/Agents/WriterAgent.php');

        $this->assertStringContainsString('LegalRulesProvider', $src);
        $this->assertStringContainsString('promptDirectiveFor(', $src);
        $this->assertStringContainsString('industryKey()', $src);
        $this->assertStringContainsString('primaryJurisdiction()', $src);
        // Interpolated into BOTH the draft and redraft heredocs. There are two
        // {$legalBlock} interpolations (buildUserMessage + buildRedraftMessage).
        $this->assertSame(2, substr_count($src, '{$legalBlock}'));
    }

    public function test_compliance_backstop_registers_legal_check(): void
    {
        $src = $this->source('app/Agents/ComplianceAgent.php');

        $this->assertStringContainsString('checkLegalCompliance(', $src);
        $this->assertStringContainsString("'legal_compliance'", $src);
        // The check is wired into the gate's $checks array, not just defined.
        $this->assertStringContainsString('$this->checkLegalCompliance($draft, $brand)', $src);
    }
}
