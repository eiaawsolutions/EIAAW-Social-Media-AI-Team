<?php

namespace Tests\Unit;

use App\Models\Brand;
use Tests\TestCase;

/**
 * Guards the "Manage connections" destination on the customer wizard.
 *
 * The fix (2026-06-05): the button must take the customer to where they actually
 * add/remove social accounts — their per-brand Metricool manage link — instead
 * of the internal read-only platform-connections table (which meant nothing to a
 * customer and read as "the button does nothing"). The link is durable (stored
 * on brands.metricool_connect_url); when absent or invalid, the wizard falls
 * back to a "request a fresh link" Livewire action so the button never dead-ends.
 *
 * Brand::metricoolManageUrl() is a pure validator over an in-memory attribute —
 * no DB round-trip — so these stay DB-free (local .env DB == prod, never write).
 */
class MetricoolManageLinkTest extends TestCase
{
    private function brandWith(?string $url): Brand
    {
        // Non-persisted instance: setAttribute only, never save() — DB untouched.
        $b = new Brand();
        $b->metricool_connect_url = $url;

        return $b;
    }

    public function test_manage_url_returns_a_valid_https_link(): void
    {
        $b = $this->brandWith('https://f.mtr.cool/IPDVUOFUIU');
        $this->assertSame('https://f.mtr.cool/IPDVUOFUIU', $b->metricoolManageUrl());
    }

    public function test_manage_url_is_null_when_unset(): void
    {
        $this->assertNull($this->brandWith(null)->metricoolManageUrl());
        $this->assertNull($this->brandWith('')->metricoolManageUrl());
        $this->assertNull($this->brandWith('   ')->metricoolManageUrl());
    }

    public function test_manage_url_rejects_non_https_and_garbage(): void
    {
        // No silent http downgrade, no "javascript:" / relative / bare-word
        // values reaching an anchor href.
        $this->assertNull($this->brandWith('http://f.mtr.cool/x')->metricoolManageUrl());
        $this->assertNull($this->brandWith('javascript:alert(1)')->metricoolManageUrl());
        $this->assertNull($this->brandWith('not a url')->metricoolManageUrl());
        $this->assertNull($this->brandWith('/agency/platform-connections')->metricoolManageUrl());
    }

    public function test_manage_url_is_trimmed(): void
    {
        $b = $this->brandWith("  https://f.mtr.cool/abc  ");
        $this->assertSame('https://f.mtr.cool/abc', $b->metricoolManageUrl());
    }

    public function test_metricool_connect_url_is_fillable(): void
    {
        $this->assertContains(
            'metricool_connect_url',
            (new Brand())->getFillable(),
            'metricool_connect_url must be fillable so the operator commands can set it.'
        );
    }

    // ---- Blade: the connected card's two branches -------------------------

    private function bladeSource(): string
    {
        return file_get_contents(
            resource_path('views/filament/agency/pages/metricool-setup.blade.php')
        );
    }

    public function test_blade_manage_connections_always_requests_a_fresh_link(): void
    {
        $src = $this->bladeSource();

        // Admin-driven model: every click requests a FRESH link via the Livewire
        // action — we never deep-link to a stored (stale, ~71h-expired) link.
        $this->assertStringContainsString('wire:click="requestFreshLink(', $src,
            'Manage connections must always trigger requestFreshLink — never a stored deep-link.');
    }

    public function test_blade_does_not_deep_link_to_a_stored_metricool_url(): void
    {
        $src = $this->bladeSource();

        // Regression: the stored-link deep-link path was removed because the
        // link expires after ~71h and would land customers on a dead Metricool
        // page. No external Metricool href, no manageUrl branch.
        $this->assertStringNotContainsString("\$brand['manageUrl']", $src,
            'The wizard must not branch on a stored manage link anymore.');
        $this->assertStringNotContainsString('f.mtr.cool', $src,
            'The wizard must not hardcode/deep-link a Metricool connect URL.');
    }

    // ---- Page: the fresh-link action -------------------------------------

    private function pageSource(): string
    {
        return file_get_contents(app_path('Filament/Agency/Pages/MetricoolSetup.php'));
    }

    public function test_page_exposes_request_fresh_link_action(): void
    {
        $this->assertTrue(
            method_exists(\App\Filament\Agency\Pages\MetricoolSetup::class, 'requestFreshLink'),
            'MetricoolSetup must expose requestFreshLink() as the Manage-connections action.'
        );
    }

    public function test_fresh_link_does_not_resend_a_stored_stale_link_to_the_customer(): void
    {
        $src = $this->pageSource();

        // Under the admin-driven model the page must NOT email the customer a
        // stored link (it would be expired). The customer-facing MetricoolConnectLink
        // send was removed; HQ mints + sends a fresh one instead.
        $this->assertStringNotContainsString('new MetricoolConnectLink(', $src,
            'requestFreshLink must not re-send a stored connect link to the customer.');
    }

    public function test_fresh_link_always_notifies_hq_via_pinned_mailer(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString('Fresh connect-link request', $src,
            'requestFreshLink must notify HQ so a fresh ~71h link gets minted + sent.');
        $this->assertStringContainsString("config('mail.support_enquiry.mailer'", $src,
            'The HQ notification must use the pinned support_enquiry (Resend) mailer.');
        $this->assertStringContainsString('brand:send-metricool-link', $src,
            'The HQ email must tell the operator exactly how to mint + send the fresh link.');
    }

    // ---- Commands: operator can store the durable link -------------------

    public function test_send_command_persists_the_url_as_the_durable_manage_link(): void
    {
        $src = file_get_contents(app_path('Console/Commands/BrandSendMetricoolLink.php'));

        $this->assertStringContainsString("forceFill(['metricool_connect_url' => \$url])", $src,
            'brand:send-metricool-link must store the sent link as the durable Manage-connections destination.');
    }

    public function test_set_command_accepts_connect_url_option(): void
    {
        $src = file_get_contents(app_path('Console/Commands/BrandSetMetricoolBlog.php'));

        $this->assertStringContainsString('--connect-url=', $src,
            'brand:set-metricool-blog must accept --connect-url to set/refresh the durable link.');
        $this->assertStringContainsString("\$brand->update(['metricool_connect_url' => \$connectUrl])", $src,
            'The --connect-url option must persist a validated https link.');
        $this->assertStringContainsString("'metricool_connect_url' => null", $src,
            '--clear must also null out the stored manage link.');
    }

    // ---- Platforms page modal: the "Connect a platform" popup -------------
    //
    // The OTHER customer surface for the same link. The Platforms-page modal
    // ("Connect a social platform") must deep-link the customer to their own
    // brand connect page — under the admin-driven model it routes to Platform
    // setup (where Manage connections requests a fresh link) rather than
    // deep-linking a stored/stale link, and it must NOT render the runaway
    // inline arrow SVG that previously blew up to fill the modal.

    private function modalSource(): string
    {
        return file_get_contents(
            resource_path('views/filament/agency/modals/connect-metricool.blade.php')
        );
    }

    public function test_modal_routes_to_platform_setup_not_a_stored_deep_link(): void
    {
        $src = $this->modalSource();

        // Admin-driven model: the modal sends the customer to Platform setup,
        // where Manage connections requests a FRESH link. It must NOT deep-link a
        // stored (stale, ~71h-expired) link.
        $this->assertStringContainsString('Go to Platform setup', $src,
            'The modal must offer the Platform setup route.');
        $this->assertStringContainsString('$setupUrl', $src,
            'The CTA must target the setup wizard URL.');
        $this->assertStringNotContainsString('$connectLink', $src,
            'The modal must not deep-link a stored connect link (it would be expired).');
        $this->assertStringNotContainsString('f.mtr.cool', $src,
            'The modal must not hardcode/deep-link a Metricool connect URL.');
    }

    public function test_modal_does_not_render_the_runaway_arrow_svg(): void
    {
        $src = $this->modalSource();

        // The giant-arrow bug: a raw inline <svg class="w-4 h-4"> rendered at
        // intrinsic size when Tailwind utilities didn't apply inside the modal,
        // filling the popup. The arrow is now a text glyph with an inline
        // font-size, so it can't blow up regardless of the CSS pipeline.
        $this->assertStringNotContainsString('<svg', $src,
            'The modal must not carry a raw inline SVG (it rendered oversized inside the modal).');
        $this->assertStringContainsString('&rarr;', $src,
            'The arrow must be a size-bounded text glyph, not an unsized SVG.');
    }
}
