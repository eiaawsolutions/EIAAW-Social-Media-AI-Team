<?php

namespace Tests\Unit;

use App\Console\Commands\SignupReconcile;
use Tests\TestCase;

/**
 * Locks the signup:reconcile daily backstop ([[signup_hardening]]) — the slow
 * sweep under the real-time webhook safety net. Catches paid signups that BOTH
 * the webhook and success() missed (e.g. a webhook outage past Stripe's 3-day
 * retry window).
 *
 * DB-free by design (local .env DB == prod — see [[metrics_capture_gap]]).
 * Tests the pure decision logic (isStranded / metadataOf via reflection on a
 * fake session), the command contract (flags, registration, schedule wiring),
 * and that BOTH reconcile commands route through the shared SignupProvisioner
 * rather than a private duplicated transaction.
 */
class SignupReconcileTest extends TestCase
{
    private function invoke(string $method, object $session): mixed
    {
        $cmd = new SignupReconcile();
        $ref = new \ReflectionMethod($cmd, $method);
        $ref->setAccessible(true);
        return $ref->invoke($cmd, $session);
    }

    /** Stripe-session-shaped stand-in. */
    private function fakeSession(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cs_test_fake',
            'metadata' => ['intent' => 'signup', 'email' => 'nobody@example.test', 'workspace_name' => 'WS', 'plan' => 'solo'],
            'customer' => 'cus_does_not_exist_'.bin2hex(random_bytes(6)),
            'customer_email' => null,
            'payment_status' => 'paid',
            'created' => 1700000000,
        ], $overrides);
    }

    // ── metadataOf: array vs StripeObject vs garbage (the method_exists trap) ──

    public function test_metadata_of_handles_plain_array(): void
    {
        $s = $this->fakeSession(['metadata' => ['intent' => 'signup', 'email' => 'a@b.test']]);
        $this->assertSame('signup', $this->invoke('metadataOf', $s)['intent']);
    }

    public function test_metadata_of_handles_stripeobject_like_toarray(): void
    {
        $obj = new class {
            public function toArray(): array { return ['intent' => 'signup', 'email' => 'x@y.test']; }
        };
        $s = $this->fakeSession(['metadata' => $obj]);
        $this->assertSame('x@y.test', $this->invoke('metadataOf', $s)['email']);
    }

    public function test_metadata_of_never_throws_on_non_object_non_array(): void
    {
        // The exact PHP 8.3 method_exists($array) TypeError class — must be
        // guarded so a malformed session can't crash the daily sweep.
        $s = $this->fakeSession(['metadata' => null]);
        $this->assertSame([], $this->invoke('metadataOf', $s));
    }

    // ── isStranded logic (source-asserted; the live method touches the DB,
    //    which is prod locally, so we lock the rule by inspection not execution) ─

    public function test_stranded_rule_checks_both_customer_and_email(): void
    {
        $src = file_get_contents(app_path('Console/Commands/SignupReconcile.php'));
        // A session is matched (NOT stranded) when EITHER a workspace has its
        // stripe_customer_id OR a user has its email. Both checks must be present.
        $this->assertMatchesRegularExpression(
            "/Workspace::where\\('stripe_customer_id'/",
            $src,
            'isStranded must resolve by Workspace.stripe_customer_id.');
        $this->assertMatchesRegularExpression(
            "/User::where\\('email'/",
            $src,
            'isStranded must also resolve by User.email (the provisioner idempotency key).');
        $this->assertStringContainsString('return true;', $src,
            'isStranded must default to stranded when neither resolves.');
    }

    // ── Command contract ──────────────────────────────────────────────────────

    public function test_command_is_registered_with_expected_flags(): void
    {
        $cmd = new SignupReconcile();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('hours'), 'must expose --hours window');
        $this->assertTrue($def->hasOption('report-only'), 'must expose --report-only (used by the scheduler)');
        $this->assertTrue($def->hasOption('dry-run'), 'must expose --dry-run');
        $this->assertSame('signup:reconcile', $cmd->getName());
    }

    public function test_both_commands_are_discoverable_by_artisan(): void
    {
        $names = array_keys(\Illuminate\Support\Facades\Artisan::all());
        $this->assertContains('signup:reconcile', $names);
        $this->assertContains('billing:reconcile-session', $names);
    }

    // ── Shared-provisioner guarantee (no duplicated transaction) ──────────────

    public function test_sweep_provisions_via_shared_provisioner(): void
    {
        $src = file_get_contents(app_path('Console/Commands/SignupReconcile.php'));
        $this->assertStringContainsString('provisionFromSession', $src,
            'The sweep must provision via the shared SignupProvisioner, not a private transaction.');
        $this->assertStringNotContainsString('DB::transaction', $src,
            'The sweep must NOT contain its own provisioning transaction.');
    }

    public function test_single_session_command_no_longer_duplicates_the_transaction(): void
    {
        $src = file_get_contents(app_path('Console/Commands/ReconcileCheckoutSession.php'));
        $this->assertStringContainsString('provisionFromSession', $src,
            'billing:reconcile-session must delegate to SignupProvisioner.');
        $this->assertStringNotContainsString('DB::transaction', $src,
            'billing:reconcile-session must no longer carry its own duplicated provisioning transaction.');
        // And the method_exists($array) trap must be gone (guarded form only).
        $this->assertStringNotContainsString("method_exists(\$rawMetadata", $src,
            'The old unguarded method_exists($rawMetadata) call must be gone.');
    }

    public function test_hq_alert_uses_the_pinned_ops_mailer(): void
    {
        $src = file_get_contents(app_path('Console/Commands/SignupReconcile.php'));
        $this->assertStringContainsString("config('mail.support_enquiry.mailer'", $src,
            'HQ stranded-signup alert must ride the Resend-pinned support_enquiry mailer.');
    }

    // ── Scheduler wiring: report-only, never unattended auto-provision ────────

    public function test_scheduled_run_is_report_only(): void
    {
        $boot = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("signup:reconcile --report-only", $boot,
            'The scheduled run MUST be --report-only so an unattended run never auto-creates accounts.');
        $this->assertStringNotContainsString("command('signup:reconcile')\n", $boot,
            'The scheduler must not run bare signup:reconcile (that would auto-provision unattended).');
    }
}
