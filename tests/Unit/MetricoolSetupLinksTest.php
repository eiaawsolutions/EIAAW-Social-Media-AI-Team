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
}
