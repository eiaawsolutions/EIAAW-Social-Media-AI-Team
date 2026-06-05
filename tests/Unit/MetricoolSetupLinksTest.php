<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the outbound links on the customer "Connect your social accounts"
 * wizard (metricool-setup.blade.php).
 *
 * History of the "Manage connections" button:
 *   1. originally pointed at the hardcoded /agency/platforms literal → 404.
 *   2. then pointed at the internal /agency/platform-connections Filament table
 *      — a registered route, but the WRONG destination: customers can't manage
 *      the real Metricool connection there, so the button felt dead to them.
 *   3. now (2026-06-05): opens the brand's durable Metricool manage link in a
 *      new tab when one is stored, else falls back to a "request a fresh link"
 *      Livewire action — never a dead button. See MetricoolManageLinkTest for
 *      the destination logic; this file guards the OTHER wizard links + the
 *      setup gate that must stay open during onboarding.
 *
 * Pure source-inspection; DB-free.
 */
class MetricoolSetupLinksTest extends TestCase
{
    private function bladeSource(): string
    {
        return file_get_contents(
            resource_path('views/filament/agency/pages/metricool-setup.blade.php')
        );
    }

    public function test_no_dead_agency_platforms_literal_in_the_wizard(): void
    {
        $src = $this->bladeSource();

        // The /agency/platforms path does not exist — the resource is at
        // /agency/platform-connections. Any hardcoded /agency/platforms link
        // (not the valid /agency/platform-connections) is a dead 404 link.
        $this->assertDoesNotMatchRegularExpression(
            "#/agency/platforms(?!-connections|-setup)#",
            $src,
            'The wizard must not link to the non-existent /agency/platforms path.'
        );
    }

    public function test_manage_connections_no_longer_dead_ends_on_the_internal_table(): void
    {
        $src = $this->bladeSource();

        // Regression: the button must NOT point customers at the internal
        // read-only platform-connections table — that is not where they manage
        // the real Metricool connection, so it reads as "nothing happened".
        $this->assertStringNotContainsString(
            "route('filament.agency.resources.platform-connections.index'",
            $src,
            'Manage connections must go to Metricool (durable link) or the fresh-link fallback, not the internal table.'
        );
    }

    public function test_go_to_setup_wizard_links_via_the_real_page_url_not_a_hardcoded_literal(): void
    {
        $src = $this->bladeSource();

        // The empty-state CTA must resolve through the Filament page's own URL
        // helper (route-name backed) rather than a brittle url('/agency/...')
        // literal, mirroring the Manage-connections fix above.
        $this->assertStringContainsString(
            'App\Filament\Agency\Pages\SetupWizard::getUrl()',
            $src,
            'The "Go to setup wizard" CTA must link via SetupWizard::getUrl().'
        );

        $this->assertDoesNotMatchRegularExpression(
            "#url\\(\\s*'/agency/setup-wizard'#",
            $src,
            'The wizard CTA must not use a hardcoded /agency/setup-wizard literal.'
        );
    }

    public function test_setup_wizard_route_is_registered(): void
    {
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('filament.agency.pages.setup-wizard'),
            'The setup-wizard page route must be registered for the CTA to resolve.'
        );
    }

    /**
     * Regression: the trial/setup gate (EnforceTrialOrSubscription) must allow
     * the Setup Wizard while a workspace has no connected brand. A fresh,
     * paying workspace has zero brands → the platform-connection gate is closed,
     * but the Setup Wizard is the ONLY place to create that first brand. If it
     * is not allow-listed, clicking "Go to setup wizard" redirects straight back
     * to metricool-setup (loop), so the button appears dead.
     */
    public function test_setup_wizard_is_allow_listed_in_the_setup_gate(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\EnforceTrialOrSubscription::class);
        $patterns = $reflection->getConstant('SETUP_ALLOWED_ROUTE_PATTERNS');

        $this->assertContains(
            'filament.agency.pages.setup-wizard',
            $patterns,
            'The setup-wizard route must be allow-listed so a no-brand workspace can reach it to create its first brand (otherwise: redirect loop).'
        );
    }

    /**
     * Regression: the FULL documented onboarding chain (HQ "Client onboarding
     * journey", stages 0→1) is metricool-setup → setup-wizard → brands create.
     * The Brands resource is the canonical brand-CREATE surface that the wizard
     * and every empty-state CTA forward to (/agency/brands?action=create). It is
     * the load-bearing step — a no-brand workspace can never satisfy the
     * connected-brand gate without it, so it MUST be allow-listed too.
     */
    public function test_brands_resource_is_allow_listed_in_the_setup_gate(): void
    {
        $reflection = new \ReflectionClass(\App\Http\Middleware\EnforceTrialOrSubscription::class);
        $patterns = $reflection->getConstant('SETUP_ALLOWED_ROUTE_PATTERNS');

        $this->assertContains(
            'filament.agency.resources.brands.*',
            $patterns,
            'The Brands resource must be allow-listed — it is the only place to create the first brand the gate is waiting for (otherwise: redirect loop one step deeper).'
        );
    }

    public function test_brands_create_route_is_registered(): void
    {
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('filament.agency.resources.brands.index'),
            'The brands index route must be registered for the "Add your first brand" deeplink to resolve.'
        );
    }
}
