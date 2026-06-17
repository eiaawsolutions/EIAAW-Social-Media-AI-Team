<?php

namespace Tests\Unit;

use App\Models\ComplianceLegalRule;
use App\Services\Compliance\LegalRulesProvider;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for the legal-rules directive renderer — the
 * single source of truth for the prompt block injected into the Strategist,
 * Writer, and the Compliance backstop. The critical guarantee mirrors the other
 * agent renderers: an empty rule set renders to '' so the prompt section is
 * suppressed entirely (un-curated brand → byte-identical to pre-feature prompt).
 */
class LegalRulesProviderTest extends TestCase
{
    private function rule(array $attrs): ComplianceLegalRule
    {
        // Unsaved model instance — fillable covers all the renderer reads.
        return new ComplianceLegalRule(array_merge([
            'industry' => 'financial_services',
            'jurisdiction' => 'MY',
            'rule_code' => 'MY-FIN-001',
            'title' => 'No guaranteed returns',
            'directive' => 'Never promise guaranteed investment returns.',
            'severity' => 'block',
        ], $attrs));
    }

    public function test_empty_rules_suppress_the_block(): void
    {
        $this->assertSame('', LegalRulesProvider::renderDirectiveBlock([], 'financial_services', 'MY'));
    }

    public function test_renders_block_and_advisory_rules_with_severity_tags(): void
    {
        $block = LegalRulesProvider::renderDirectiveBlock(
            [
                $this->rule(['directive' => 'Never promise guaranteed investment returns.', 'severity' => 'block', 'source' => 'CMSA 2007']),
                $this->rule(['rule_code' => 'MY-FIN-002', 'directive' => 'Include required risk disclosures.', 'severity' => 'advisory']),
            ],
            'financial_services',
            'MY',
        );

        $this->assertStringContainsString('Legal & advertising-standards rules', $block);
        $this->assertStringContainsString('Financial Services', $block);
        $this->assertStringContainsString('jurisdiction MY', $block);
        // Block rule tagged MUST, advisory tagged SHOULD.
        $this->assertStringContainsString('[MUST] Never promise guaranteed investment returns.', $block);
        $this->assertStringContainsString('[SHOULD] Include required risk disclosures.', $block);
        // Source citation surfaced for auditability.
        $this->assertStringContainsString('(source: CMSA 2007)', $block);
        // The hard-constraint framing the agents rely on.
        $this->assertStringContainsString('never publishes', $block);
    }

    public function test_provider_constants_align_with_wildcard(): void
    {
        $this->assertSame('*', ComplianceLegalRule::WILDCARD);
    }
}
