<?php

namespace Tests\Unit;

use App\Agents\DesignerAgent;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the provider-aware media re-host seam in DesignerAgent.
 *
 * The bug this locks down: after the Blotato→Metricool publishing switch, the
 * Designer still re-hosted every image through per-workspace Blotato at
 * generation time. Metricool-onboarded workspaces have NO Blotato key, so the
 * re-host threw, the draft kept asset_url=null, and Compliance hard-failed
 * platform_publishability — stranding every such customer at wizard Stage 7.
 *
 * Under PUBLISH_PROVIDER=metricool the Designer must NOT touch Blotato: it
 * returns the public source URL unchanged, and MetricoolPublisher normalises it
 * at publish time with the shared agency token. Under PUBLISH_PROVIDER=blotato
 * (rollback) it must still re-host through the workspace's Blotato account.
 *
 * Pure unit test — no DB. rehostMedia() is exercised directly via reflection
 * with in-memory models, because local .env points at the prod database and
 * tests must never touch it.
 */
class DesignerMediaRehostTest extends TestCase
{
    private function designer(): DesignerAgent
    {
        // LlmGateway + EmbeddingService are never invoked by rehostMedia(); a
        // bare mock satisfies the constructor without any network/DB.
        return new DesignerAgent(
            $this->createMock(LlmGateway::class),
            $this->createMock(EmbeddingService::class),
        );
    }

    private function invokeRehost(DesignerAgent $agent, Brand $brand, string $url): array
    {
        $m = new ReflectionMethod($agent, 'rehostMedia');
        $m->setAccessible(true);

        return $m->invoke($agent, $brand, $url, 999);
    }

    public function test_metricool_provider_returns_source_url_untouched_without_blotato(): void
    {
        config()->set('services.publishing.provider', 'metricool');

        // A Metricool-onboarded workspace: no Blotato key handle at all. If the
        // Designer tried to re-host through Blotato this would throw.
        $workspace = new Workspace(['blotato_api_key_handle' => null]);
        $brand = new Brand();
        $brand->setRelation('workspace', $workspace);

        $falUrl = 'https://v3b.fal.media/files/b/0a9c68cf/example.png';
        $result = $this->invokeRehost($this->designer(), $brand, $falUrl);

        $this->assertTrue($result['ok']);
        $this->assertSame($falUrl, $result['url'], 'Metricool path must pass the public URL straight through.');
        $this->assertSame('', $result['error']);
    }

    public function test_empty_provider_config_defaults_to_metricool_passthrough(): void
    {
        // config() returns null for an explicit-null key; the seam must treat
        // null/empty as the default provider (metricool), not blow up.
        config()->set('services.publishing.provider', null);

        $brand = new Brand();
        $brand->setRelation('workspace', new Workspace(['blotato_api_key_handle' => null]));

        $url = 'https://assets.example.com/library/photo.jpg';
        $result = $this->invokeRehost($this->designer(), $brand, $url);

        $this->assertTrue($result['ok']);
        $this->assertSame($url, $result['url']);
    }

    public function test_blotato_provider_without_key_fails_loudly_not_silently(): void
    {
        // Rollback path: PUBLISH_PROVIDER=blotato. A workspace with no Blotato
        // key must surface a hard failure (ok=false) rather than a passthrough —
        // proving the Metricool change didn't accidentally make Blotato lenient.
        config()->set('services.publishing.provider', 'blotato');

        $workspace = new Workspace(['blotato_api_key_handle' => null]);
        $workspace->id = 3;
        $workspace->slug = 'the-bear-hug-enterprise';
        $brand = new Brand();
        $brand->workspace_id = 3;
        $brand->setRelation('workspace', $workspace);

        $result = $this->invokeRehost($this->designer(), $brand, 'https://v3b.fal.media/files/x/y.png');

        $this->assertFalse($result['ok'], 'Blotato path with no key must fail, not pass the URL through.');
        $this->assertStringContainsString('Blotato', $result['error']);
        $this->assertSame('', $result['url']);
    }
}
