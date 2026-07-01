<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use Tests\TestCase;

/**
 * Locks the two-tier semantic dedup decision (workstream D). The old gate blocked
 * only near-verbatim (>= 0.85); the soft band [0.78, 0.85) now catches THEMATIC
 * recycling and routes it to the redraft loop — then relents once the loop has
 * given up so the pipeline isn't starved.
 */
class DedupTieringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the thresholds so the test is independent of env overrides.
        config([
            'services.compliance.dedup_hard_threshold' => 0.85,
            'services.compliance.dedup_soft_threshold' => 0.78,
            'services.compliance.dedup_soft_relent_after' => 3,
        ]);
    }

    public function test_near_verbatim_is_a_hard_fail_regardless_of_revisions(): void
    {
        [$result] = ComplianceAgent::decideDedupResult(0.90, 0);
        $this->assertSame('fail', $result);

        // Even after many revisions, a near-verbatim clone stays a hard fail.
        [$result] = ComplianceAgent::decideDedupResult(0.92, 9);
        $this->assertSame('fail', $result);
    }

    public function test_thematic_band_fails_while_redraft_attempts_remain(): void
    {
        // 0.80 is in [0.78, 0.85): thematic recycling. Fresh draft (0 revisions)
        // → fail so the Writer re-drafts with a new angle.
        [$result, $reason] = ComplianceAgent::decideDedupResult(0.80, 0);
        $this->assertSame('fail', $result);
        $this->assertStringContainsStringIgnoringCase('fresher angle', $reason);

        // Still failing on the 2nd revision (revision_count 2 < relentAfter 3).
        [$result] = ComplianceAgent::decideDedupResult(0.83, 2);
        $this->assertSame('fail', $result);
    }

    public function test_thematic_band_relents_to_warning_after_redraft_loop_gives_up(): void
    {
        // revision_count 3 >= relentAfter 3 → the loop has exhausted its attempts.
        // Downgrade to a non-blocking warning so the draft isn't stranded.
        [$result, $reason] = ComplianceAgent::decideDedupResult(0.80, 3);
        $this->assertSame('warning', $result);
        $this->assertStringContainsStringIgnoringCase('human review', $reason);
    }

    public function test_below_soft_threshold_passes(): void
    {
        [$result] = ComplianceAgent::decideDedupResult(0.60, 0);
        $this->assertSame('pass', $result);

        // Just under the soft threshold still passes.
        [$result] = ComplianceAgent::decideDedupResult(0.7799, 0);
        $this->assertSame('pass', $result);
    }

    public function test_boundaries_are_inclusive_on_the_lower_edge(): void
    {
        // Exactly at soft → enters the thematic band (fail on a fresh draft).
        [$result] = ComplianceAgent::decideDedupResult(0.78, 0);
        $this->assertSame('fail', $result);

        // Exactly at hard → hard fail.
        [$result] = ComplianceAgent::decideDedupResult(0.85, 0);
        $this->assertSame('fail', $result);
    }

    public function test_thresholds_are_config_tunable(): void
    {
        config([
            'services.compliance.dedup_hard_threshold' => 0.95,
            'services.compliance.dedup_soft_threshold' => 0.90,
        ]);

        // 0.88 is now BELOW the soft threshold (0.90) → passes, where under the
        // default 0.78 soft it would have failed. Proves the knob is live.
        [$result] = ComplianceAgent::decideDedupResult(0.88, 0);
        $this->assertSame('pass', $result);
    }
}
