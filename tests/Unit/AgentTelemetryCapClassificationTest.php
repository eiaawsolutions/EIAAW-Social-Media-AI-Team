<?php

namespace Tests\Unit;

use App\Services\Monitoring\AgentTelemetry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the "the agent is at a plan cap, not broken" classification on the
 * super-admin Agents monitor.
 *
 * The bug this locks down: the Video agent legitimately returns
 * AgentResult::fail('Daily video budget reached: $4.00 / $4.00 …') when a Solo
 * workspace burns its daily AI-video budget. That is the cost circuit breaker
 * working AS DESIGNED — it refused to overspend. But every fail() row landed in
 * audit_log as outcome=failed, so 5 cap hits in 24h tipped the agent over
 * FAIL_RATIO and the monitor showed a red FAILING pill with the remedy
 * "Unfamiliar error. Open storage/logs/laravel.log for the full stack" — telling
 * the operator to chase a stack trace that doesn't exist. The same affliction hit
 * the Designer agent ("Daily image budget reached").
 *
 * Fix under test:
 *   - benign cap/policy refusals are EXCLUDED from the failure ratio (a workspace
 *     can't flip the agent to red by hitting its own cap)
 *   - they derive a distinct, non-alarming 'capped' status
 *   - the remedy is the reset/upgrade guidance, never "read the stack trace"
 *   - a genuine fault mixed in still drives 'failing' with the real error
 *
 * DB-free: drives the private deriveStatus()/reasonForStatus()/nextActionFor()
 * via reflection with hand-built audit collections (matches FalAccountLockoutTest).
 */
class AgentTelemetryCapClassificationTest extends TestCase
{
    private const DAILY_VIDEO_CAP = 'Daily video budget reached: $4.00 / $4.00 on the Solo plan. Resets at midnight UTC. Upgrade at /agency/billing for a higher daily ceiling.';
    private const DAILY_IMAGE_CAP = 'Daily image budget reached: $1.50 / $1.50 on the Solo plan. Resets at midnight UTC. Upgrade at /agency/billing for a higher daily ceiling.';
    private const MONTHLY_VIDEO_CAP = 'Solo plan monthly AI-video allowance reached (4/4). Resets on the 1st, or upgrade at /agency/billing for a higher video allowance. Use a still image for this draft in the meantime.';
    private const PLATFORM_SKIP = "Platform 'x' does not accept short-form video — skip VideoAgent on this draft.";
    private const REAL_FAULT = 'Video generation failed: SQLSTATE[HY000] connection refused';

    // ── isBenignCapOrPolicy classification ───────────────────────────────────

    public function test_cap_and_policy_messages_are_classified_benign(): void
    {
        $this->assertTrue($this->isBenign(self::DAILY_VIDEO_CAP));
        $this->assertTrue($this->isBenign(self::DAILY_IMAGE_CAP));
        $this->assertTrue($this->isBenign(self::MONTHLY_VIDEO_CAP));
        $this->assertTrue($this->isBenign(self::PLATFORM_SKIP));
    }

    public function test_real_faults_and_provider_lockouts_are_not_benign(): void
    {
        // A genuine code/infra fault.
        $this->assertFalse($this->isBenign(self::REAL_FAULT));
        // FAL balance exhaustion is a real operational event with its OWN top-up
        // remedy — it must NOT be swept into the benign-cap bucket.
        $this->assertFalse($this->isBenign('Video generation failed: FAL.AI account locked (balance exhausted).'));
        $this->assertFalse($this->isBenign(null));
        $this->assertFalse($this->isBenign(''));
    }

    // ── status derivation ────────────────────────────────────────────────────

    public function test_only_cap_hits_derive_capped_not_failing(): void
    {
        // 6 runs, 5 are daily-cap refusals, 1 succeeded — the exact shape the
        // dashboard showed (5 failed / 6 runs). Pre-fix this was FAILING.
        $audit = $this->audit([
            ['failed', self::DAILY_VIDEO_CAP, 1],
            ['failed', self::DAILY_VIDEO_CAP, 2],
            ['failed', self::DAILY_VIDEO_CAP, 3],
            ['failed', self::DAILY_VIDEO_CAP, 4],
            ['failed', self::DAILY_VIDEO_CAP, 5],
            ['completed', null, 60],
        ]);

        $this->assertSame('capped', $this->deriveFromAudit($audit));
    }

    public function test_real_fault_majority_still_derives_failing(): void
    {
        $audit = $this->audit([
            ['failed', self::REAL_FAULT, 1],
            ['failed', self::REAL_FAULT, 2],
            ['failed', self::REAL_FAULT, 3],
            ['completed', null, 60],
        ]);

        $this->assertSame('failing', $this->deriveFromAudit($audit));
    }

    public function test_caps_do_not_dilute_a_real_fault_majority(): void
    {
        // 2 real faults + 2 successes among the NON-benign runs = 0.5 ratio →
        // failing. The 4 cap hits must not drag the denominator up and hide it.
        $audit = $this->audit([
            ['failed', self::REAL_FAULT, 1],
            ['failed', self::REAL_FAULT, 2],
            ['completed', null, 50],
            ['completed', null, 51],
            ['failed', self::DAILY_VIDEO_CAP, 3],
            ['failed', self::DAILY_VIDEO_CAP, 4],
            ['failed', self::DAILY_VIDEO_CAP, 5],
            ['failed', self::DAILY_VIDEO_CAP, 6],
        ]);

        $this->assertSame('failing', $this->deriveFromAudit($audit));
    }

    public function test_a_real_fault_outranks_a_more_recent_cap_hit_when_ratio_trips(): void
    {
        // Most recent run is a benign cap, but real faults dominate the
        // non-benign runs → failing wins (real breakage is the worse state).
        $audit = $this->audit([
            ['failed', self::DAILY_VIDEO_CAP, 1],   // newest
            ['failed', self::REAL_FAULT, 2],
            ['failed', self::REAL_FAULT, 3],
            ['failed', self::REAL_FAULT, 4],
        ]);

        $this->assertSame('failing', $this->deriveFromAudit($audit));
    }

    // ── operator guidance ────────────────────────────────────────────────────

    public function test_capped_remedy_is_reset_upgrade_never_stack_trace(): void
    {
        $action = $this->nextAction('capped', self::DAILY_VIDEO_CAP, 'video');

        $this->assertStringContainsStringIgnoringCase('resets at midnight utc', $action);
        $this->assertStringContainsString('/agency/billing', $action);
        // The pre-fix bug: a cap hit was told to read the stack trace.
        $this->assertStringNotContainsStringIgnoringCase('storage/logs/laravel.log', $action);
        $this->assertStringNotContainsStringIgnoringCase('unfamiliar error', $action);
        $this->assertStringNotContainsStringIgnoringCase('stack', $action);
    }

    public function test_monthly_video_allowance_remedy_points_to_the_first_reset(): void
    {
        $action = $this->nextAction('capped', self::MONTHLY_VIDEO_CAP, 'video');

        $this->assertStringContainsStringIgnoringCase('resets on the 1st', $action);
        $this->assertStringContainsString('/agency/billing', $action);
        $this->assertStringNotContainsStringIgnoringCase('storage/logs/laravel.log', $action);
    }

    public function test_platform_skip_remedy_is_no_action_needed(): void
    {
        $action = $this->nextAction('capped', self::PLATFORM_SKIP, 'video');

        $this->assertStringContainsStringIgnoringCase('no action needed', $action);
        $this->assertStringContainsStringIgnoringCase('correctly skipped', $action);
        $this->assertStringNotContainsStringIgnoringCase('storage/logs/laravel.log', $action);
    }

    public function test_cap_remedy_wins_even_if_a_cap_row_is_ever_seen_as_failing(): void
    {
        // Defense in depth: even passed status=failing, a benign error text must
        // route to the cap remedy, not the generic "open the stack trace" line.
        $action = $this->nextAction('failing', self::DAILY_IMAGE_CAP, 'designer');

        $this->assertStringContainsStringIgnoringCase('resets at midnight utc', $action);
        $this->assertStringNotContainsStringIgnoringCase('storage/logs/laravel.log', $action);
    }

    // ── recovered-burst window (2026-06-01) ───────────────────────────────────

    public function test_recovered_real_fault_burst_outside_failing_window_is_not_failing(): void
    {
        // The FAL-lockout shape: a heavy real-fault burst that ended >6h ago, with
        // no runs since. It must NOT hold the agent red — the burst is outside the
        // FAILING_WINDOW. buildRow() scopes the ratio to the recent window, so this
        // drops to healthy (it had runs in the 24h lookback, none recent).
        $audit = $this->audit([
            ['failed', self::REAL_FAULT, 7 * 60],   // 7h ago
            ['failed', self::REAL_FAULT, 7 * 60 + 1],
            ['failed', self::REAL_FAULT, 7 * 60 + 2],
            ['failed', self::REAL_FAULT, 7 * 60 + 3],
            ['completed', null, 8 * 60],            // last success 8h ago
        ]);

        $status = $this->buildRowStatus($audit);
        $this->assertNotSame('failing', $status, 'A burst that ended >6h ago must not read failing.');
        $this->assertSame('healthy', $status);
    }

    public function test_real_fault_burst_inside_failing_window_still_reads_failing(): void
    {
        // The same burst, but RECENT (within the last 6h) — this is a live problem
        // and must still go red.
        $audit = $this->audit([
            ['failed', self::REAL_FAULT, 5],
            ['failed', self::REAL_FAULT, 6],
            ['failed', self::REAL_FAULT, 7],
            ['completed', null, 60],
        ]);

        $this->assertSame('failing', $this->buildRowStatus($audit));
    }

    // ── reflection helpers ───────────────────────────────────────────────────

    /**
     * @param array<int, array{0:string,1:?string,2:int}> $rows  [outcome, error, minutesAgo]
     */
    private function audit(array $rows): Collection
    {
        $now = now();

        return collect($rows)->map(fn (array $r) => [
            'outcome' => $r[0],
            'occurred_at' => (clone $now)->subMinutes($r[2]),
            'latency_ms' => 1402,
            'error' => $r[1],
            'workspace_id' => 1,
            'brand_id' => 1,
        ]);
    }

    /**
     * Drive the REAL buildRow() path (not the hand-rolled ratio in
     * deriveFromAudit) so the FAILING_WINDOW scoping is exercised end-to-end.
     * Returns the derived status string. Pipelines + horizon are empty so the
     * status is driven purely by the audit window.
     */
    private function buildRowStatus(Collection $audit): string
    {
        $m = new ReflectionMethod(AgentTelemetry::class, 'buildRow');
        $m->setAccessible(true);

        $row = $m->invoke(
            new AgentTelemetry(),
            ['class' => 'App\\Agents\\VideoAgent', 'role' => 'video', 'label' => 'Video'],
            $audit,
            collect(),
            ['available' => false, 'queues' => [], 'error' => null],
        );

        return (string) $row['status'];
    }

    private function deriveFromAudit(Collection $audit): string
    {
        $allFailures = $audit->where('outcome', 'failed')->values();
        $benign = $allFailures->filter(fn (array $r) => $this->isBenign($r['error'] ?? null))->values();
        $real = $allFailures->reject(fn (array $r) => $this->isBenign($r['error'] ?? null))->values();

        $total = $audit->count();
        $ratioDenominator = $total - $benign->count();
        $failRatio = $ratioDenominator > 0 ? $real->count() / $ratioDenominator : 0.0;

        $m = new ReflectionMethod(AgentTelemetry::class, 'deriveStatus');
        $m->setAccessible(true);

        return (string) $m->invoke(
            new AgentTelemetry(),
            $failRatio,
            $total,
            $ratioDenominator,
            $audit->first(),
            $benign->first(),
            collect(),
            collect(),
            Carbon::now(),
        );
    }

    private function isBenign(?string $error): bool
    {
        $m = new ReflectionMethod(AgentTelemetry::class, 'isBenignCapOrPolicy');
        $m->setAccessible(true);

        return (bool) $m->invoke(new AgentTelemetry(), $error);
    }

    private function nextAction(string $status, ?string $error, string $role): string
    {
        $m = new ReflectionMethod(AgentTelemetry::class, 'nextActionFor');
        $m->setAccessible(true);

        return (string) $m->invoke(new AgentTelemetry(), $status, $error, $role);
    }
}
