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

    public function test_blade_opens_the_manage_url_in_a_new_tab_when_present(): void
    {
        $src = $this->bladeSource();

        $this->assertStringContainsString("\$brand['manageUrl']", $src,
            'The blade must branch on the per-brand manageUrl.');
        $this->assertStringContainsString('target="_blank"', $src,
            'The Metricool manage link must open in a new tab (the customer keeps the wizard open to Re-check).');
        $this->assertStringContainsString('rel="noopener noreferrer"', $src,
            'External links must carry rel="noopener noreferrer".');
    }

    public function test_blade_falls_back_to_request_fresh_link_when_absent(): void
    {
        $src = $this->bladeSource();

        $this->assertStringContainsString('wire:click="requestFreshLink(', $src,
            'When no manage link is stored, the button must trigger the requestFreshLink fallback — never a dead link.');
    }

    // ---- Page: the fallback action ---------------------------------------

    private function pageSource(): string
    {
        return file_get_contents(app_path('Filament/Agency/Pages/MetricoolSetup.php'));
    }

    public function test_page_exposes_request_fresh_link_action(): void
    {
        $this->assertTrue(
            method_exists(\App\Filament\Agency\Pages\MetricoolSetup::class, 'requestFreshLink'),
            'MetricoolSetup must expose requestFreshLink() as the Livewire fallback action.'
        );
    }

    public function test_page_exposes_manage_url_per_brand(): void
    {
        $this->assertStringContainsString("'manageUrl' => \$b->metricoolManageUrl()", $this->pageSource(),
            'refresh() must surface each brand\'s durable manage link to the view.');
    }

    public function test_fresh_link_send_is_synchronous_and_never_claims_a_noop_send(): void
    {
        $src = $this->pageSource();

        // Same delivery discipline as BrandSendMetricoolLink: synchronous send,
        // and never report "emailed" through a log/array/keyless-resend transport.
        $this->assertStringContainsString('->send(new MetricoolConnectLink(', $src,
            'The fresh-link re-send must be synchronous so a transport failure is caught.');
        $this->assertStringContainsString('transportDelivers(', $src,
            'The page must gate the customer re-send on a delivering transport (no fake "sent").');
        $this->assertStringContainsString("in_array(\$transport, ['log', 'array'], true)", $src,
            'transportDelivers must treat log/array as non-delivering.');
    }

    public function test_fresh_link_always_notifies_hq(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString('Fresh connect-link request', $src,
            'requestFreshLink must always notify HQ so an expired share-link gets re-minted.');
        $this->assertStringContainsString("config('mail.support_enquiry.mailer'", $src,
            'The HQ notification must use the pinned support_enquiry (Resend) mailer.');
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
    // brand connect page exactly like the wizard does — and it must NOT render
    // the runaway inline arrow SVG that previously blew up to fill the modal.

    private function platformsPageSource(): string
    {
        return file_get_contents(app_path(
            'Filament/Agency/Resources/PlatformConnections/Pages/ManagePlatformConnections.php'
        ));
    }

    private function modalSource(): string
    {
        return file_get_contents(
            resource_path('views/filament/agency/modals/connect-metricool.blade.php')
        );
    }

    public function test_platforms_page_exposes_connect_link_via_the_model_accessor(): void
    {
        // connectLink() must delegate to Brand::metricoolManageUrl() (the same
        // null-safe https validator the wizard uses) — never invent a URL.
        $this->assertTrue(
            method_exists(
                \App\Filament\Agency\Resources\PlatformConnections\Pages\ManagePlatformConnections::class,
                'connectLink'
            ),
            'ManagePlatformConnections must expose connectLink() to its modal.'
        );
        $this->assertStringContainsString('->metricoolManageUrl()', $this->platformsPageSource(),
            'connectLink() must source the URL from the validated model accessor.');
    }

    public function test_modal_deep_links_to_the_connect_page_in_a_new_tab(): void
    {
        $src = $this->modalSource();

        $this->assertStringContainsString('$this->connectLink()', $src,
            'The modal must read the per-brand connect link from the page.');
        $this->assertStringContainsString('href="{{ $connectLink }}"', $src,
            'The primary CTA must point at the customer\'s own connect link when present.');
        $this->assertStringContainsString('target="_blank"', $src,
            'The connect link must open in a new tab so the table stays open to Refresh.');
        $this->assertStringContainsString('rel="noopener noreferrer"', $src,
            'External links must carry rel="noopener noreferrer".');
    }

    public function test_modal_falls_back_to_platform_setup_when_no_link_stored(): void
    {
        $src = $this->modalSource();

        // The else-branch keeps the original "Go to Platform setup" CTA so a
        // brand with no stored link never dead-ends.
        $this->assertStringContainsString('Go to Platform setup', $src,
            'With no connect link, the modal must still offer the Platform setup route.');
        $this->assertStringContainsString('$setupUrl', $src,
            'The fallback CTA must target the setup wizard URL.');
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
