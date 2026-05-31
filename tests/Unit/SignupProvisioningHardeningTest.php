<?php

namespace Tests\Unit;

use App\Services\Billing\SignupProvisioner;
use App\Services\Billing\SignupProvisionResult;
use App\Support\MailTransport;
use Tests\TestCase;

/**
 * Locks the signup-flow hardening that closes two recurring failure classes:
 *
 *   Defect 1 — credential / HQ-notify mail silently dropped because it rode the
 *              default MAIL_MAILER (log / half-wired mailgun) instead of the
 *              Resend transport whose deliverability we maintain.
 *
 *   Defect 2 — a paid Stripe checkout whose success() redirect never ran left
 *              the customer charged with NO account and NO recovery path. The
 *              webhook is now an idempotent safety net. See
 *              [[signup_provisioning_gap]].
 *
 * DB-free by design: the codebase's local .env DB == prod, so these tests must
 * never write. They exercise the branches that return BEFORE any DB access
 * (skip / missing-metadata), the pure value object, the transport guard, and
 * the source/config wiring of the two call sites.
 */
class SignupProvisioningHardeningTest extends TestCase
{
    /** A minimal Stripe-session-shaped stand-in (no \Stripe\* needed). */
    private function fakeSession(array $metadata, ?string $customerEmail = null): object
    {
        return (object) [
            'id' => 'cs_test_fake',
            'metadata' => $metadata,           // plain array — provisioner handles non-StripeObject
            'customer' => 'cus_test_fake',
            'customer_email' => $customerEmail,
            'subscription' => null,
        ];
    }

    // ── Defect 2: provisioner decision logic (DB-free branches) ─────────────

    public function test_non_signup_intent_is_skipped_without_touching_the_db(): void
    {
        $result = (new SignupProvisioner())->provisionFromSession(
            $this->fakeSession(['intent' => 'upgrade', 'plan' => 'studio'])
        );

        $this->assertSame(SignupProvisionResult::SKIPPED, $result->status);
        $this->assertFalse($result->hasAccount());
        $this->assertStringContainsString('non-signup intent', (string) $result->reason);
    }

    public function test_missing_metadata_fails_closed_without_touching_the_db(): void
    {
        // intent=signup but no name/workspace_name/email → must fail, not throw,
        // and must NOT attempt a DB write.
        $result = (new SignupProvisioner())->provisionFromSession(
            $this->fakeSession(['intent' => 'signup', 'plan' => 'solo'])
        );

        $this->assertSame(SignupProvisionResult::FAILED, $result->status);
        $this->assertFalse($result->hasAccount());
        $this->assertSame('missing metadata', $result->reason);
    }

    public function test_absent_intent_is_treated_as_signup_eligible(): void
    {
        // A session with NO intent key but complete signup metadata should NOT
        // be skipped (legacy sessions predate the intent tag). It will proceed
        // to the DB write, so we only assert it is NOT short-circuited as
        // skipped — we stop before the write by omitting required fields would
        // change the branch, so instead assert the skip guard specifically
        // ignores a null intent.
        $result = (new SignupProvisioner())->provisionFromSession(
            // null intent + missing name → reaches the metadata gate (FAILED),
            // proving it passed the intent gate rather than being SKIPPED.
            $this->fakeSession(['plan' => 'solo', 'email' => 'x@example.com'])
        );

        $this->assertNotSame(SignupProvisionResult::SKIPPED, $result->status);
        $this->assertSame(SignupProvisionResult::FAILED, $result->status);
    }

    // ── Result value object ─────────────────────────────────────────────────

    public function test_result_helpers_classify_outcomes(): void
    {
        $this->assertTrue(SignupProvisionResult::skipped('x')->status === SignupProvisionResult::SKIPPED);
        $this->assertFalse(SignupProvisionResult::skipped('x')->hasAccount());
        $this->assertFalse(SignupProvisionResult::failed('x')->hasAccount());
        $this->assertFalse(SignupProvisionResult::failed('x')->wasProvisioned());
    }

    // ── Defect 1: mail transport guard ──────────────────────────────────────

    public function test_log_and_array_transports_are_flagged_non_delivering(): void
    {
        $this->assertTrue(MailTransport::isNonDelivering('log'));
        $this->assertTrue(MailTransport::isNonDelivering('array'));
        $this->assertNotNull(MailTransport::cannotDeliverReason('log'));
        $this->assertNotNull(MailTransport::cannotDeliverReason('array'));
    }

    public function test_resend_without_package_key_cannot_deliver(): void
    {
        // Simulate the EXACT 99504fd bug: transport key present, package key
        // empty → fails only in the worker. The guard must catch it.
        config(['services.resend.key' => 'rk_present', 'resend.api_key' => null]);
        $this->assertNotNull(MailTransport::cannotDeliverReason('resend'));
        $this->assertStringContainsString('resend.api_key', MailTransport::cannotDeliverReason('resend'));

        config(['services.resend.key' => 'rk_present', 'resend.api_key' => 'rk_present']);
        $this->assertNull(MailTransport::cannotDeliverReason('resend'));
    }

    public function test_welcome_mailer_is_pinned_to_the_resend_backed_operational_mailer(): void
    {
        // Credentials must ride the same pinned transport as cap_warning, not
        // the default MAIL_MAILER.
        $this->assertSame(config('mail.cap_warning.mailer', 'resend'), MailTransport::welcomeMailer());
    }

    // ── Defect 1: default transport + call-site wiring (source/config) ──────

    public function test_env_example_default_mailer_is_resend_not_log(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^MAIL_MAILER=resend\s*$/m', $env,
            '.env.example must default MAIL_MAILER to resend so credential mail rides the reliable transport.');
        $this->assertDoesNotMatchRegularExpression('/^MAIL_MAILER=log\s*$/m', $env,
            'MAIL_MAILER must never default to log in .env.example.');
    }

    public function test_both_credential_call_sites_use_the_shared_pinned_sender(): void
    {
        // success() and the webhook safety net must BOTH send credentials via
        // SignupProvisioner::queueWelcome (the single pinned sender) — not an
        // ad-hoc Mail::to()->queue() that inherits the default mailer.
        $controller = file_get_contents(app_path('Http/Controllers/BillingController.php'));
        $this->assertStringContainsString('SignupProvisioner::queueWelcome', $controller,
            'success() must send credentials via the shared pinned queueWelcome().');
        $this->assertStringNotContainsString('new WelcomeWithCredentials', $controller,
            'success() must NOT construct the welcome mailable inline (that path bypasses the pinned transport).');
    }

    public function test_hq_setup_request_mail_is_pinned_not_default(): void
    {
        $page = file_get_contents(app_path('Filament/Agency/Pages/MetricoolSetup.php'));
        $this->assertStringContainsString("Mail::mailer(\$hqMailer)", $page,
            'The HQ setup-request notification must be pinned to an explicit mailer, not the default.');
        $this->assertStringContainsString("config('mail.support_enquiry.mailer'", $page,
            'The HQ notification mailer should resolve from the Resend-pinned support_enquiry config.');
    }

    // ── Defect 2: webhook safety-net wiring (source) ────────────────────────

    public function test_webhook_provisions_signup_via_the_shared_provisioner(): void
    {
        $webhook = file_get_contents(app_path('Http/Controllers/StripeWebhookController.php'));

        $this->assertStringContainsString('provisionSignupIfNeeded', $webhook,
            'The webhook must have a signup recovery path.');
        $this->assertStringContainsString('SignupProvisioner', $webhook,
            'The webhook must provision via the SHARED SignupProvisioner (no duplicated transaction).');
        // The recovery must run BEFORE the workspace-found gate (the workspace
        // does not exist yet when success() failed).
        $this->assertStringContainsString("'checkout.session.completed' && ! \$workspaceId", $webhook,
            'Signup recovery must trigger when no workspace resolves yet (the stranded-customer case).');
    }

    public function test_webhook_only_provisions_signup_intent_sessions(): void
    {
        $webhook = file_get_contents(app_path('Http/Controllers/StripeWebhookController.php'));
        $this->assertStringContainsString("\$intent !== 'signup'", $webhook,
            'The webhook recovery must skip non-signup sessions (e.g. upgrades).');
    }
}
