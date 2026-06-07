<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Services\Metricool\MetricoolConnectLinkSender;
use Tests\TestCase;

/**
 * Guards the one-click "Store & send a fresh connect-link" automation: the
 * MetricoolConnectLinkSender service + its HQ-onboarding page action + the
 * deep-link from the customer's "fresh link request" email.
 *
 * The service's early-return validation branches (not_mapped, bad_url) run
 * BEFORE any DB write or mail send, so they're testable on a non-persisted
 * Brand — DB-free (local .env DB == prod, never write). The send/store/stamp
 * happy path needs a real brand+mailer, so it's covered by source-inspection
 * (it shares MetricoolConnectLink + the transport guards, already tested).
 */
class MetricoolConnectLinkSenderTest extends TestCase
{
    private function sender(): MetricoolConnectLinkSender
    {
        return new MetricoolConnectLinkSender();
    }

    /** Non-persisted brand — setAttribute only, never save(). */
    private function brand(array $attrs): Brand
    {
        $b = new Brand();
        $b->id = $attrs['id'] ?? 999;
        foreach ($attrs as $k => $v) {
            $b->{$k} = $v;
        }

        return $b;
    }

    public function test_rejects_a_brand_with_no_blog_id_before_sending(): void
    {
        $r = $this->sender()->send($this->brand(['metricool_blog_id' => null]), 'https://f.mtr.cool/x');

        $this->assertFalse($r['ok']);
        $this->assertSame('not_mapped', $r['code']);
    }

    public function test_rejects_a_non_https_url_before_sending(): void
    {
        // blogId present so we reach the URL check (which precedes any DB/mail).
        $brand = $this->brand(['metricool_blog_id' => '6325160']);

        foreach (['http://f.mtr.cool/x', 'javascript:alert(1)', 'not a url', 'ftp://x/y'] as $bad) {
            $r = $this->sender()->send($brand, $bad);
            $this->assertFalse($r['ok'], "should reject {$bad}");
            $this->assertSame('bad_url', $r['code'], "wrong code for {$bad}");
        }
    }

    // ---- Service applies the same transport guards as the command --------

    private function serviceSource(): string
    {
        return file_get_contents(app_path('Services/Metricool/MetricoolConnectLinkSender.php'));
    }

    public function test_service_guards_against_noop_and_keyless_transports(): void
    {
        $src = $this->serviceSource();

        $this->assertStringContainsString("in_array(\$transport, ['log', 'array'], true)", $src,
            'The service must treat log/array as non-delivering (no fake "sent").');
        $this->assertStringContainsString("empty(config('services.resend.key')) || empty(config('resend.api_key'))", $src,
            'The service must require BOTH Resend keys before sending (transport + package client).');
    }

    public function test_service_sends_synchronously_and_stamps_only_after_send(): void
    {
        $src = $this->serviceSource();

        $this->assertStringContainsString('->send(new MetricoolConnectLink(', $src,
            'The service must send synchronously so transport failures surface.');

        // The store/stamp must come AFTER the send (in the source order), so a
        // failed send leaves the brand state untouched.
        $sendPos = strpos($src, '->send(new MetricoolConnectLink(');
        $stampPos = strpos($src, "'metricool_connect_link_sent_at' => now()");
        $this->assertNotFalse($sendPos);
        $this->assertNotFalse($stampPos);
        $this->assertGreaterThan($sendPos, $stampPos,
            'link_sent must only be stamped after a confirmed send.');
    }

    // ---- HQ onboarding page: the one-click action -----------------------

    private function pageSource(): string
    {
        return file_get_contents(app_path('Filament/Pages/MetricoolOnboarding.php'));
    }

    public function test_onboarding_page_exposes_send_connect_link_action(): void
    {
        $this->assertTrue(
            method_exists(\App\Filament\Pages\MetricoolOnboarding::class, 'sendConnectLink'),
            'MetricoolOnboarding must expose sendConnectLink() as the one-click action.'
        );
        $this->assertStringContainsString('MetricoolConnectLinkSender', $this->pageSource(),
            'The page action must delegate to the shared sender service (one code path with the command).');
    }

    public function test_onboarding_page_is_super_admin_only(): void
    {
        $src = $this->pageSource();
        $this->assertStringContainsString('is_super_admin', $src,
            'The onboarding page (which can email customers) must stay super-admin gated.');
    }

    public function test_onboarding_blade_wires_the_paste_box_to_the_action(): void
    {
        $blade = file_get_contents(resource_path('views/filament/pages/metricool-onboarding.blade.php'));

        $this->assertStringContainsString('wire:model="connectUrlInputs.', $blade,
            'The paste-box must bind to the per-brand connectUrlInputs model.');
        $this->assertStringContainsString('wire:click="sendConnectLink(', $blade,
            'The Send button must trigger sendConnectLink for the brand.');
    }

    // ---- The deep-link from the customer fresh-link request -------------

    public function test_request_fresh_link_email_deep_links_to_the_onboarding_console(): void
    {
        $src = file_get_contents(app_path('Filament/Agency/Pages/MetricoolSetup.php'));

        $this->assertStringContainsString('MetricoolOnboarding::getUrl(', $src,
            'requestFreshLink must build a deep-link into the HQ onboarding console.');
        $this->assertStringContainsString("panel: 'admin'", $src,
            'The deep-link must target the admin panel (super-admin gated).');
        $this->assertStringContainsString("['brand' => \$brand->id]", $src,
            'The deep-link must focus the requesting brand.');
    }

    public function test_onboarding_route_exists_for_the_deep_link(): void
    {
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('filament.admin.pages.metricool-onboarding'),
            'The onboarding page route must be registered for the email deep-link to resolve.'
        );
    }
}
