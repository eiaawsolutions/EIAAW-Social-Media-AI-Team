<?php

namespace Tests\Unit;

use App\Services\Publishing\MetricoolPublisher;
use App\Services\Publishing\PublisherFactory;
use RuntimeException;
use Tests\TestCase;

/**
 * PublisherFactory — the single construction point for the publish path since
 * the Blotato decommission. Metricool is now the sole publisher; the factory no
 * longer reads PUBLISH_PROVIDER (the flag is dead). DB-free; asserts the factory
 * builds Metricool when configured and surfaces a misconfiguration loudly rather
 * than silently mis-routing customer posts.
 */
class PublisherFactoryTest extends TestCase
{
    public function test_makes_metricool_publisher_when_configured(): void
    {
        config([
            'services.publishing.provider' => 'metricool',
            'services.metricool.api_token' => 'mc_real_token',
            'services.metricool.user_id' => 4872275,
        ]);

        $pub = (new PublisherFactory())->make();
        $this->assertInstanceOf(MetricoolPublisher::class, $pub);
        $this->assertSame('metricool', $pub->key());
    }

    public function test_factory_ignores_the_dead_publish_provider_flag(): void
    {
        // Post-decommission the factory no longer reads PUBLISH_PROVIDER. Even a
        // stale 'blotato' value must still build Metricool (the sole publisher),
        // never resurrect a Blotato publisher.
        config([
            'services.publishing.provider' => 'blotato',
            'services.metricool.api_token' => 'mc_real_token',
            'services.metricool.user_id' => 4872275,
        ]);

        $this->assertInstanceOf(MetricoolPublisher::class, (new PublisherFactory())->make());
    }

    public function test_metricool_without_config_throws_loudly(): void
    {
        // No Metricool token → must throw, NOT silently no-op. A misconfigured
        // publish provider must surface so the operator can fix it; mis-routing
        // (or dropping) customer posts is worse than a loud failure.
        config([
            'services.metricool.api_token' => '',
            'services.metricool.user_id' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/');
        (new PublisherFactory())->make();
    }
}
