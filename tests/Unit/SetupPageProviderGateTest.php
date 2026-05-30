<?php

namespace Tests\Unit;

use App\Filament\Agency\Pages\MetricoolSetup;
use App\Filament\Agency\Pages\PlatformSetup;
use Tests\TestCase;

/**
 * Guards the provider-aware gating of the two onboarding pages so that exactly
 * ONE setup surface is ever active. Regression cover for the bug where
 * /agency/platform-setup kept rendering the dead Blotato handoff after the
 * Metricool migration because the legacy page was never decommissioned and
 * both pages shared the "Platform setup" nav label.
 *
 * Pure config-driven logic; DB-free.
 *
 * @see [[metricool_publishing_switch]] [[metricool_onboarding]]
 */
class SetupPageProviderGateTest extends TestCase
{
    private function withProvider(string $provider): void
    {
        config()->set('services.publishing.provider', $provider);
    }

    public function test_metricool_is_the_active_setup_page_by_default(): void
    {
        $this->withProvider('metricool');

        $this->assertTrue(MetricoolSetup::canAccess());
        $this->assertTrue(MetricoolSetup::shouldRegisterNavigation());

        // Legacy Blotato page is dormant under Metricool: no nav entry.
        $this->assertFalse(PlatformSetup::isActiveProvider());
        $this->assertFalse(PlatformSetup::shouldRegisterNavigation());
    }

    public function test_blotato_rollback_reactivates_the_legacy_page(): void
    {
        $this->withProvider('blotato');

        $this->assertTrue(PlatformSetup::isActiveProvider());
        $this->assertTrue(PlatformSetup::shouldRegisterNavigation());

        // Metricool wizard steps aside under the rollback: no nav entry,
        // direct access blocked.
        $this->assertFalse(MetricoolSetup::canAccess());
        $this->assertFalse(MetricoolSetup::shouldRegisterNavigation());
    }

    public function test_unknown_or_empty_provider_falls_back_to_metricool(): void
    {
        $this->withProvider('');

        $this->assertTrue(MetricoolSetup::canAccess());
        $this->assertFalse(PlatformSetup::isActiveProvider());
    }

    public function test_exactly_one_setup_page_is_navigable_per_provider(): void
    {
        foreach (['metricool', 'blotato'] as $provider) {
            $this->withProvider($provider);

            $navigable = (int) MetricoolSetup::shouldRegisterNavigation()
                + (int) PlatformSetup::shouldRegisterNavigation();

            $this->assertSame(
                1,
                $navigable,
                "Exactly one setup page must be navigable under provider [{$provider}], got {$navigable}.",
            );
        }
    }
}
