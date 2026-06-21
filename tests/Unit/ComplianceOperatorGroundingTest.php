<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use Tests\TestCase;

/**
 * Regression: operator-authored customised posts (agent_role='operator',
 * prompt_version='customised-post.v1') never go through the Writer, so they
 * carry NO grounding_sources by design. factual_grounding hard-failed them at
 * 0.00 with "Writer cited no grounding sources" — a message literally false for
 * a post no Writer touched, and a process artifact rather than a real violation
 * (the check verifies CITED sources resolve to rows; it does not fact-check).
 *
 * Fix: operator copy with no sources is EXEMPT — recorded as a non-blocking
 * 'warning' (the gate treats warning as passing). Agent-written drafts are
 * never exempt: the Writer must cite. This locks the pure predicate.
 */
class ComplianceOperatorGroundingTest extends TestCase
{
    public function test_operator_copy_with_no_sources_is_exempt(): void
    {
        $this->assertTrue(ComplianceAgent::groundingIsExemptForOperator('operator', []));
    }

    public function test_agent_copy_with_no_sources_is_not_exempt(): void
    {
        // The Writer is contractually required to cite — an agent draft with no
        // sources is still a real fail, exactly as before.
        $this->assertFalse(ComplianceAgent::groundingIsExemptForOperator('writer', []));
        $this->assertFalse(ComplianceAgent::groundingIsExemptForOperator('strategist', []));
        $this->assertFalse(ComplianceAgent::groundingIsExemptForOperator(null, []));
    }

    public function test_operator_copy_with_sources_is_not_exempt(): void
    {
        // If an operator draft somehow DID carry sources, verify them normally —
        // the exemption is strictly for the empty-sources artifact.
        $this->assertFalse(ComplianceAgent::groundingIsExemptForOperator('operator', [
            ['source_type' => 'website_page', 'source_excerpt' => 'x'],
        ]));
    }
}
