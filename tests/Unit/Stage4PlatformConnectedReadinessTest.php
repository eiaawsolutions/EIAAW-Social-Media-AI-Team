<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the Setup-Wizard Stage 4 ("At least one platform connected") regression
 * that stranded a live Metricool customer (The Bear Hug, brand #8): the brand had
 * 4 active platform_connections detected live from Metricool, yet the wizard kept
 * showing Stage 4 as "Complete Blotato Account first" and never flipped green.
 *
 * Two independent root causes, both covered here:
 *
 *   Bug A — stale cache. MetricoolConnectionService::sync() upserts the
 *           platform_connections rows but never busted SetupReadiness's 30s
 *           per-brand cache, so the wizard kept rendering the pre-connection
 *           snapshot. Every OTHER state-changing surface (agents, BrandCorpusSeed,
 *           AutonomyLane, CustomisedPostScheduler) invalidates after a write;
 *           sync() was the lone connection seam that didn't.
 *
 *   Bug B — provider-wrong blocker. stage4_platformConnected() hardcoded a Blotato
 *           gate (workspace->hasBlotatoConnected()). Blotato is OFF under the
 *           Metricool switch, so that gate is permanently false and stamped every
 *           Metricool brand blockedBy='blotato_account' — a blocker that can never
 *           clear. The fix makes Stage 4 provider-aware (mirroring stage 0): under
 *           Metricool the prerequisite is the brand's own metricool_blog_id.
 *
 * Source-inspection only — the unit suite runs against the live DB connection
 * (sqlite is commented out in phpunit.xml), so a row-writing test would pollute
 * prod. We assert on the source of the two files instead.
 *
 * @see [[metricool_publishing_switch]] [[metricool_onboarding]] [[metricool_multitenancy]]
 */
class Stage4PlatformConnectedReadinessTest extends TestCase
{
    private function readinessSource(): string
    {
        return file_get_contents(app_path('Services/Readiness/SetupReadiness.php'));
    }

    private function syncSource(): string
    {
        return file_get_contents(app_path('Services/Metricool/MetricoolConnectionService.php'));
    }

    // ── Bug A: sync() must bust the readiness cache ─────────────────────────

    public function test_sync_invalidates_setup_readiness_cache(): void
    {
        $src = $this->syncSource();

        $this->assertStringContainsString(
            'use App\Services\Readiness\SetupReadiness;',
            $src,
            'MetricoolConnectionService must import SetupReadiness to invalidate its cache.',
        );

        $this->assertMatchesRegularExpression(
            '/app\(\s*SetupReadiness::class\s*\)\s*->\s*invalidate\(\s*\$brand\s*\)/',
            $src,
            'sync() must call SetupReadiness::invalidate($brand) so Stage 4 flips immediately '
            . 'on the same request, instead of lagging up to 30s behind the connection.',
        );
    }

    public function test_invalidate_happens_after_the_revoke_pass_so_the_final_state_is_cached_fresh(): void
    {
        $src = $this->syncSource();

        // The invalidate must come AFTER the revoke update inside sync(), so the
        // next readiness read recomputes against the fully-reconciled row set
        // (connected upserts + stale-network revokes), not a half-applied state.
        $revokePos = strpos($src, "->update(['status' => 'revoked'])");
        $invalidatePos = strpos($src, '->invalidate($brand)');

        $this->assertNotFalse($revokePos, 'Could not locate the revoke update in sync().');
        $this->assertNotFalse($invalidatePos, 'Could not locate the invalidate call in sync().');
        $this->assertGreaterThan(
            $revokePos,
            $invalidatePos,
            'invalidate() must run after the revoke pass so the freshly-cached readiness '
            . 'reflects the final reconciled connection set.',
        );
    }

    // ── Bug B: Stage 4 blocker must be provider-aware, not hardcoded Blotato ──

    public function test_stage4_does_not_hardcode_a_blotato_account_blocker(): void
    {
        $src = $this->readinessSource();

        // The dead Blotato blocker id must be gone from Stage 4. Its presence was
        // the exact string the wizard rendered as "Complete Blotato Account first"
        // for a Metricool customer whose socials were already connected.
        $this->assertStringNotContainsString(
            "'blotato_account'",
            $src,
            'Stage 4 must not reference the dead "blotato_account" blocker id — it can '
            . 'never clear under the Metricool provider and stranded a live customer.',
        );
    }

    public function test_stage4_is_provider_aware_and_gates_metricool_on_blog_id(): void
    {
        $src = $this->readinessSource();

        // Isolate the stage4_platformConnected method body so we assert on the
        // right detector and not stage 0's (which is legitimately provider-aware).
        $this->assertTrue(
            (bool) preg_match(
                '/function stage4_platformConnected\(.*?\)\s*:\s*ReadinessStage\s*\{(.*?)\n    \}/s',
                $src,
                $m,
            ),
            'Could not isolate stage4_platformConnected() body.',
        );
        $body = $m[1];

        // It must branch on the publishing provider, mirroring stage 0.
        $this->assertStringContainsString(
            "config('services.publishing.provider'",
            $body,
            'Stage 4 must read the publishing provider to pick the right prerequisite.',
        );

        // Under Metricool the prerequisite is the brand's own metricool_blog_id
        // (its secure space) — not a workspace Blotato account.
        $this->assertStringContainsString(
            'metricool_blog_id',
            $body,
            'Under Metricool, Stage 4 must gate on the brand having a metricool_blog_id, '
            . 'not on a Blotato account that never exists.',
        );

        // The blocker, when set, must point at a real prerequisite stage id.
        // 'publishing_account' is stage 0 (provider-aware: "Social accounts
        // connected" for Metricool / "Publishing account ready" for Blotato).
        $this->assertStringContainsString(
            "'publishing_account'",
            $body,
            "Stage 4's blocker must point at the real 'publishing_account' stage (stage 0), "
            . 'so the customer has a resolvable next action.',
        );
    }

    public function test_blotato_rollback_path_still_gates_on_workspace_blotato_account(): void
    {
        $src = $this->readinessSource();

        preg_match(
            '/function stage4_platformConnected\(.*?\)\s*:\s*ReadinessStage\s*\{(.*?)\n    \}/s',
            $src,
            $m,
        );
        $body = $m[1] ?? '';

        // The rollback branch must keep the Blotato workspace gate so flipping
        // PUBLISH_PROVIDER=blotato restores the original (correct) behaviour.
        $this->assertStringContainsString(
            'hasBlotatoConnected()',
            $body,
            'The blotato rollback branch must still gate on workspace->hasBlotatoConnected().',
        );
    }
}
