<?php

namespace Tests\Unit;

use Anthropic\Client;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression guard for the "Generate with AI writer" outage.
 *
 * The Asset-library AI writer (CustomisedNarrativeWriter) and the upload-time
 * vision tagger (BrandAssetTagger) were written against the OLD
 * anthropic-php/anthropic-php API — `Anthropic::factory()->withApiKey()->make()`
 * + `->messages()->create([...])`. The project installs anthropic-ai/sdk v0.17,
 * whose entry class is Anthropic\Client (constructor takes `apiKey:`) and whose
 * call is `->messages->create(named: args)` with camelCase keys. The old calls
 * fatalled at runtime, which surfaced to the operator as "AI writer could not
 * draft" (and silently degraded asset tagging to filename-only).
 *
 * These tests lock in the correct SDK surface — both at the binding level (the
 * SDK really exposes what we call) and at the source level (the two services
 * don't regress to the dead API). No network call, no DB.
 */
class AnthropicSdkUsageTest extends TestCase
{
    public function test_installed_sdk_exposes_the_surface_the_services_call(): void
    {
        // Constructs with a named apiKey arg (NOT Anthropic::factory()).
        $client = new Client(apiKey: 'sk-test-not-real');

        // `messages` is a property holding the service (NOT a `messages()` method).
        $this->assertTrue(
            property_exists($client, 'messages'),
            'anthropic-ai/sdk Client must expose a `messages` property',
        );

        // create() accepts the exact named args both services pass.
        $params = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionMethod($client->messages, 'create'))->getParameters(),
        );
        foreach (['maxTokens', 'model', 'system', 'messages'] as $named) {
            $this->assertContains(
                $named,
                $params,
                "messages->create() must accept `{$named}` (camelCase, v0.17 named-param API)",
            );
        }
    }

    public function test_services_do_not_regress_to_the_dead_anthropic_php_api(): void
    {
        foreach ([
            app_path('Services/Imagery/CustomisedNarrativeWriter.php'),
            app_path('Services/Imagery/BrandAssetTagger.php'),
        ] as $file) {
            $src = (string) file_get_contents($file);
            $base = basename($file);

            // The dead API must not reappear.
            $this->assertStringNotContainsString('Anthropic::factory(', $src, "{$base} must not use Anthropic::factory()");
            $this->assertStringNotContainsString('->withApiKey(', $src, "{$base} must not use ->withApiKey()");
            $this->assertStringNotContainsString('->messages()->create(', $src, "{$base} must not call ->messages() as a method");

            // The correct v0.17 surface must be present.
            $this->assertStringContainsString('new Client(', $src, "{$base} must construct Anthropic\\Client directly");
            $this->assertStringContainsString('->messages->create(', $src, "{$base} must call ->messages->create()");
        }
    }
}
