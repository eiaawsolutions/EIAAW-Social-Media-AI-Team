<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use Tests\TestCase;

/**
 * Truth-table for ComplianceAgent::decideLegalResult — the pure legal-gate
 * decision extracted from checkLegalCompliance.
 *
 * Regression: drafts #440 and #401 returned verdict="pass" with ZERO blocking
 * violations (only an advisory note) yet FAILED because the old logic ANDed a
 * `score >= 0.80` clause onto the verdict. The judge's verdict + blocking
 * violations are authoritative; the score is a confidence FLOOR (sanity net for
 * a self-contradictory judge), not an 80% quality bar. These tests lock that the
 * three fail conditions are independent and the score floor only bites an
 * implausibly-low pass.
 */
class ComplianceLegalDecisionTest extends TestCase
{
    private function payload(array $over = []): array
    {
        return array_merge([
            'score' => 0.95,
            'verdict' => 'pass',
            'violations' => [],
            'reasoning' => 'Looks fine.',
        ], $over);
    }

    public function test_clean_pass(): void
    {
        $d = ComplianceAgent::decideLegalResult($this->payload());
        $this->assertSame('pass', $d['result']);
        $this->assertSame('No legal violations detected.', $d['reason']);
    }

    /**
     * THE BUG: verdict=pass, no blocking violation, score 0.78 (between the old
     * 0.80 gate and the new 0.50 floor) must now PASS. Mirrors prod draft #440.
     */
    public function test_pass_verdict_with_midrange_score_and_advisory_only_passes(): void
    {
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'score' => 0.78,
            'verdict' => 'pass',
            'violations' => [
                ['severity' => 'advisory', 'rule_code' => 'GL-AD-002', 'reason' => 'Mild comparative note.'],
            ],
        ]));

        $this->assertSame('pass', $d['result'], 'verdict=pass + advisory-only + score>=floor must pass');
        // The advisory violation is preserved for the audit trail, just not blocking.
        $this->assertCount(1, $d['violations']);
    }

    public function test_blocking_violation_fails_even_with_high_score(): void
    {
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'score' => 0.99, // a high score must NOT rescue a real blocking violation
            'verdict' => 'pass', // even a contradictory pass verdict must not override a block
            'violations' => [
                ['severity' => 'block', 'rule_code' => 'MY-HLTH-001', 'reason' => 'Disease cure claim.'],
            ],
        ]));

        $this->assertSame('fail', $d['result']);
        $this->assertStringContainsString('MY-HLTH-001', $d['reason']);
    }

    public function test_fail_verdict_fails_regardless_of_score(): void
    {
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'score' => 0.95,
            'verdict' => 'fail',
            'violations' => [],
        ]));
        $this->assertSame('fail', $d['result']);
    }

    public function test_pass_verdict_with_implausibly_low_score_is_held(): void
    {
        // Self-contradictory judge: says pass but score 0.30 — the confidence
        // floor catches it and holds for review (fail-closed sanity net).
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'score' => 0.30,
            'verdict' => 'pass',
            'violations' => [],
        ]));
        $this->assertSame('fail', $d['result']);
        $this->assertStringContainsString('low confidence', $d['reason']);
    }

    public function test_score_exactly_at_floor_passes(): void
    {
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'score' => 0.50,
            'verdict' => 'pass',
            'violations' => [],
        ]));
        $this->assertSame('pass', $d['result']);
    }

    public function test_malformed_verdict_fails_closed(): void
    {
        // A non-'pass' / missing verdict must hold (the gate is fail-closed on
        // anything it can't read as an explicit pass).
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'verdict' => 'maybe',
        ]));
        $this->assertSame('fail', $d['result']);

        $missing = ComplianceAgent::decideLegalResult([
            'score' => 0.9, 'violations' => [], 'reasoning' => 'x',
        ]);
        $this->assertSame('fail', $missing['result']);
    }

    public function test_missing_severity_counts_as_blocking(): void
    {
        // A violation with no severity must NOT be silently treated as advisory.
        $d = ComplianceAgent::decideLegalResult($this->payload([
            'verdict' => 'pass',
            'violations' => [
                ['rule_code' => 'GL-AD-001', 'reason' => 'Unclear severity.'],
            ],
        ]));
        $this->assertSame('fail', $d['result']);
    }
}
