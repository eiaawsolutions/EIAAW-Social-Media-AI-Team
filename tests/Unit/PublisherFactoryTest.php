<?php

namespace Tests\Unit;

use App\Services\Publishing\BlotatoPublisher;
use App\Services\Publishing\MetricoolPublisher;
use App\Services\Publishing\PublisherFactory;
use RuntimeException;
use Tests\TestCase;

/**
 * PublisherFactory — the PUBLISH_PROVIDER flag seam for the Blotato→Metricool
 * switch. DB-free; asserts which publisher the flag selects and that a
 * misconfigured Metricool surfaces loudly rather than silently mis-routing.
 */
class PublisherFactoryTest extends TestCase
{
    public function test_blotato_flag_makes_blotato_publisher(): void
    {
        config(['services.publishing.provider' => 'blotato']);

        $this->assertInstanceOf(BlotatoPublisher::class, (new PublisherFactory())->make());
    }

    public function test_metricool_flag_with_config_makes_metricool_publisher(): void
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

    public function test_default_provider_is_metricool(): void
    {
        // No explicit provider set → default must be metricool (the switch).
        config([
            'services.publishing.provider' => null,
            'services.metricool.api_token' => 'mc_real_token',
            'services.metricool.user_id' => 4872275,
        ]);

        $this->assertInstanceOf(MetricoolPublisher::class, (new PublisherFactory())->make());
    }

    public function test_metricool_flag_without_config_throws_loudly(): void
    {
        // PUBLISH_PROVIDER=metricool but no token → must throw, NOT silently
        // fall back to Blotato (mis-routing customer posts is worse than a
        // loud failure the operator can fix).
        config([
            'services.publishing.provider' => 'metricool',
            'services.metricool.api_token' => '',
            'services.metricool.user_id' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not configured/');
        (new PublisherFactory())->make();
    }

    public function test_unknown_provider_throws(): void
    {
        config(['services.publishing.provider' => 'hootsuite']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown PUBLISH_PROVIDER/');
        (new PublisherFactory())->make();
    }
}
