<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the outbound links on the customer "Connect your social accounts"
 * wizard (metricool-setup.blade.php).
 *
 * Regression cover: "Manage connections" used to point at the hardcoded
 * /agency/platforms literal, which is NOT a real route (the Filament resource
 * lives at /agency/platform-connections) — so the button 404'd. It must resolve
 * via the real route name instead.
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

    public function test_manage_connections_uses_the_real_platform_connections_route(): void
    {
        $src = $this->bladeSource();

        $this->assertStringContainsString(
            "route('filament.agency.resources.platform-connections.index'",
            $src,
            'Manage connections must link via the real platform-connections route name.'
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

    public function test_the_target_route_actually_exists(): void
    {
        // Sanity: the route name the button relies on is registered.
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('filament.agency.resources.platform-connections.index'),
            'The platform-connections index route must be registered for the link to resolve.'
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
}
