<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the brand:send-metricool-link command + its email view.
 *
 * The load-bearing safety property: the command must NOT pretend to "send"
 * through a no-op transport (log/array) or a Resend mailer with no key —
 * EIAAW's default MAIL_MAILER is 'log', so a naive send would silently
 * vanish into the log and report success. These tests pin the guard's
 * reasoning by source inspection (DB-free, no real mailer wired).
 */
class BrandSendMetricoolLinkTest extends TestCase
{
    private function commandSource(): string
    {
        return file_get_contents(app_path('Console/Commands/BrandSendMetricoolLink.php'));
    }

    private function mailSource(): string
    {
        return file_get_contents(app_path('Mail/MetricoolConnectLink.php'));
    }

    private function viewSource(): string
    {
        return file_get_contents(resource_path('views/emails/metricool-connect-link.blade.php'));
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'brand:send-metricool-link',
            app(\Illuminate\Contracts\Console\Kernel::class)->all(),
            'brand:send-metricool-link must be discoverable as an artisan command.'
        );
    }

    public function test_guard_rejects_log_and_array_noop_transports(): void
    {
        $src = $this->commandSource();

        $this->assertStringContainsString("['log', 'array']", $src,
            'The guard must treat log and array as non-delivering transports.');
        $this->assertStringContainsString('does NOT deliver real email', $src,
            'The guard must explain that log/array do not deliver, not silently send.');
    }

    public function test_guard_requires_a_resend_key_before_sending(): void
    {
        $src = $this->commandSource();

        $this->assertStringContainsString("empty(config('services.resend.key'))", $src,
            'The guard must refuse to send via Resend when the transport key is unset.');
        $this->assertStringContainsString('RESEND_KEY', $src,
            'The remediation must name the RESEND_KEY env that wires Resend.');
    }

    public function test_guard_also_checks_the_package_client_key(): void
    {
        $src = $this->commandSource();

        // The resend-laravel package client reads resend.api_key, NOT
        // services.resend.key. A populated transport key + empty package key
        // fails ONLY in the worker after "Sent" is printed — so the guard must
        // check resend.api_key too.
        $this->assertStringContainsString("empty(config('resend.api_key'))", $src,
            'The guard must also check resend.api_key (the package client key).');
    }

    public function test_resend_config_points_at_the_same_handle_env(): void
    {
        // config/resend.php must read env('RESEND_KEY') so the package client
        // and the mail transport share one Infisical handle.
        $cfg = file_get_contents(config_path('resend.php'));
        $this->assertStringContainsString("env('RESEND_KEY')", $cfg,
            'config/resend.php must read RESEND_KEY (the shared handle env).');
    }

    public function test_resend_api_key_is_in_the_secrets_resolve_allow_list(): void
    {
        // Without this, the secret:// handle in RESEND_KEY stays a literal
        // string in config('resend.api_key') and the worker send fails.
        $this->assertContains('resend.api_key', config('secrets.resolve'),
            'resend.api_key must be allow-listed for Infisical resolution.');
    }

    public function test_guard_runs_before_send_and_aborts(): void
    {
        $src = $this->commandSource();

        // The guard is invoked, and a non-null return bails out — so a no-op
        // transport never reaches ->send(). Assert both the call and the
        // short-circuit exist, and that the guard call precedes the send.
        $this->assertStringContainsString('$guard = $this->assertTransportCanDeliver($mailer);', $src,
            'The command must call the transport guard.');
        $this->assertStringContainsString('return $guard;', $src,
            'A non-null guard result must short-circuit the command.');

        $guardPos = strpos($src, '$guard = $this->assertTransportCanDeliver($mailer);');
        $sendPos = strpos($src, '->send(new MetricoolConnectLink(');
        $this->assertNotFalse($guardPos);
        $this->assertNotFalse($sendPos);
        $this->assertLessThan($sendPos, $guardPos,
            'The transport guard must run before the send call.');
    }

    public function test_command_sends_synchronously_so_errors_surface(): void
    {
        $src = $this->commandSource();

        // ->send (not ->queue) so a transport failure surfaces as a non-zero
        // exit here, not silently in a background worker after "success".
        $this->assertStringContainsString('->send(new MetricoolConnectLink(', $src,
            'The command must send synchronously so failures surface immediately.');
        $this->assertStringNotContainsString('->queue(new MetricoolConnectLink(', $src,
            'The command must not queue the send (would hide transport errors).');
    }

    public function test_link_is_only_stamped_after_a_successful_send(): void
    {
        $src = $this->commandSource();

        // The stamp write must come AFTER the try/catch around the send, so a
        // failed send leaves the brand un-stamped (state stays 'mapped').
        $sendPos = strpos($src, '->send(new MetricoolConnectLink(');
        $stampPos = strpos($src, "forceFill(['metricool_connect_link_sent_at'");

        $this->assertNotFalse($sendPos);
        $this->assertNotFalse($stampPos);
        $this->assertGreaterThan($sendPos, $stampPos,
            'metricool_connect_link_sent_at must only be stamped after the send succeeds.');
    }

    public function test_mail_is_resend_pinned_via_cap_warning_sender(): void
    {
        $mail = $this->mailSource();

        $this->assertStringContainsString("config('mail.cap_warning.from_address')", $mail,
            'The mailable should reuse the established EIAAW operational from-address.');
    }

    public function test_view_renders_the_connect_url_and_cta(): void
    {
        $view = $this->viewSource();

        $this->assertStringContainsString('{{ $connectUrl }}', $view,
            'The email must render the connect URL.');
        $this->assertStringContainsString('Connect my accounts', $view,
            'The email must carry the primary connect CTA.');
        // The "check now" deeplink must point at the real wizard route path.
        $this->assertStringContainsString('/agency/metricool-setup', $this->mailSource(),
            'The verify deeplink must target the real metricool-setup wizard path.');
    }
}
