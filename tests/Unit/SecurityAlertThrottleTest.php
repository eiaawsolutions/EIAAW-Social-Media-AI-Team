<?php

namespace Tests\Unit;

use App\Services\Security\DetectorVerdict;
use App\Services\Security\SecurityAlertThrottle;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for the throttle. We use the `array` cache driver during
 * tests (Laravel default in phpunit.xml) so each test gets a fresh
 * bucket without needing Redis.
 */
class SecurityAlertThrottleTest extends TestCase
{
    private SecurityAlertThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        // Force a clean cache for every test. The array driver clears
        // between processes but not between tests in the same run.
        Cache::flush();
        $this->throttle = new SecurityAlertThrottle();
        // Pin config so changes to the defaults don't break the test.
        config([
            'security.alerts.per_workspace_per_hour' => 3,
            'security.alerts.global_high_per_hour' => 4,
            'security.alerts.medium_burst_threshold' => 2,
            'security.alerts.medium_burst_window_minutes' => 60,
        ]);
    }

    private function highVerdict(): DetectorVerdict
    {
        return new DetectorVerdict(
            verdict: DetectorVerdict::VERDICT_MALICIOUS,
            severity: DetectorVerdict::SEVERITY_HIGH,
            detectorLayer: 'layer1.heuristic',
            category: 'instruction_override',
        );
    }

    private function mediumVerdict(): DetectorVerdict
    {
        return new DetectorVerdict(
            verdict: DetectorVerdict::VERDICT_SUSPICIOUS,
            severity: DetectorVerdict::SEVERITY_MEDIUM,
            detectorLayer: 'layer1.heuristic',
            category: 'exfiltration',
        );
    }

    public function test_high_severity_alerts_immediately(): void
    {
        $decision = $this->throttle->shouldAlert($this->highVerdict(), workspaceId: 1);
        $this->assertTrue($decision['allow']);
    }

    public function test_high_severity_is_capped_globally(): void
    {
        // Cap is 4 — first 4 allowed, 5th suppressed.
        for ($i = 1; $i <= 4; $i++) {
            $d = $this->throttle->shouldAlert($this->highVerdict(), workspaceId: $i);
            $this->assertTrue($d['allow'], "HIGH alert {$i} should be allowed");
        }
        $d = $this->throttle->shouldAlert($this->highVerdict(), workspaceId: 99);
        $this->assertFalse($d['allow']);
        $this->assertSame('global_high_cap_reached', $d['reason']);
    }

    public function test_medium_alerts_below_burst_threshold_are_suppressed(): void
    {
        // Burst threshold = 2 — the first MEDIUM event should NOT alert.
        $d = $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $this->assertFalse($d['allow']);
        $this->assertStringContainsString('below_burst_threshold', $d['reason']);
    }

    public function test_medium_alerts_at_burst_threshold(): void
    {
        // First call (count=1) suppressed; second (count=2) crosses threshold.
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $d = $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $this->assertTrue($d['allow']);
    }

    public function test_medium_alerts_capped_per_workspace(): void
    {
        // Get past burst threshold (2 calls) so further calls are alert-eligible.
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        // Now 3rd, 4th, 5th — cap is 3 per workspace per hour.
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);

        $d = $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        $this->assertFalse($d['allow']);
        $this->assertStringContainsString('per_workspace_cap_reached', $d['reason']);
    }

    public function test_suppressed_counter_drains_on_next_alert(): void
    {
        // Build up some suppressed events.
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);  // suppressed (burst)
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);  // allowed (count=2)
        // Push past per-ws cap so suppressed counter ticks.
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);  // allowed (count=3)
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);  // suppressed (cap)
        $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);  // suppressed (cap)

        // A HIGH event arriving for the same workspace should report the
        // suppressed count from the per-workspace bucket.
        $d = $this->throttle->shouldAlert($this->highVerdict(), workspaceId: 1);
        $this->assertTrue($d['allow']);
        $this->assertGreaterThan(0, $d['suppressed_since_last_alert']);
    }

    public function test_workspaces_have_independent_buckets(): void
    {
        // Fill ws=1 past per-workspace cap.
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 1);
        }
        // ws=2 should still be at zero — its bucket is separate.
        $d = $this->throttle->shouldAlert($this->mediumVerdict(), workspaceId: 2);
        $this->assertFalse($d['allow']);
        $this->assertStringContainsString('below_burst_threshold', $d['reason']);
    }
}
