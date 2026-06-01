<?php

namespace Tests\Unit;

use App\Agents\VideoAgent;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the provider-aware media re-host seam in VideoAgent — the twin of
 * DesignerMediaRehostTest.
 *
 * The bug this locks down: VideoAgent was MISSED when DesignerAgent got the
 * provider-aware rehostMedia fix after the Blotato→Metricool switch. It kept
 * unconditionally re-hosting the generated clip through per-workspace Blotato,
 * which throws for any Metricool-onboarded workspace (no Blotato key) — so every
 * reel/video draft failed at publish with a stale still image, and re-running
 * VideoAgent could never fix it.
 *
 * Under PUBLISH_PROVIDER=metricool VideoAgent must NOT touch Blotato: it returns
 * the public source URL (fal.media / public storage / library public_url)
 * unchanged, and MetricoolPublisher normalises it at publish time. Under
 * PUBLISH_PROVIDER=blotato (rollback) it must still re-host through the
 * workspace's Blotato account, and fail loudly when there is no key.
 *
 * Pure unit test — no DB. rehostMedia() is exercised directly via reflection
 * with in-memory models, because local .env points at the prod database.
 */
class VideoMediaRehostTest extends TestCase
{
    private function video(): VideoAgent
    {
        // LlmGateway is never invoked by rehostMedia(); a bare mock satisfies
        // the BaseAgent constructor without any network/DB.
        return new VideoAgent($this->createMock(LlmGateway::class));
    }

    private function invokeRehost(VideoAgent $agent, Brand $brand, string $url): array
    {
        $m = new ReflectionMethod($agent, 'rehostMedia');
        $m->setAccessible(true);

        return $m->invoke($agent, $brand, $url, 999);
    }

    public function test_metricool_provider_returns_video_url_untouched_without_blotato(): void
    {
        config()->set('services.publishing.provider', 'metricool');

        // Metricool-onboarded workspace: no Blotato key. If VideoAgent tried to
        // re-host through Blotato this would throw — the exact stranding bug.
        $workspace = new Workspace(['blotato_api_key_handle' => null]);
        $brand = new Brand();
        $brand->setRelation('workspace', $workspace);

        $falVideoUrl = 'https://v3b.fal.media/files/b/0a9c8787/TnAorv_VYSSN6JfdVfI.mp4';
        $result = $this->invokeRehost($this->video(), $brand, $falVideoUrl);

        $this->assertTrue($result['ok']);
        $this->assertSame($falVideoUrl, $result['url'], 'Metricool path must pass the public video URL straight through.');
        $this->assertSame('', $result['error']);
    }

    public function test_empty_provider_config_defaults_to_metricool_passthrough(): void
    {
        config()->set('services.publishing.provider', null);

        $brand = new Brand();
        $brand->setRelation('workspace', new Workspace(['blotato_api_key_handle' => null]));

        $url = 'https://assets.example.com/library/clip.mp4';
        $result = $this->invokeRehost($this->video(), $brand, $url);

        $this->assertTrue($result['ok']);
        $this->assertSame($url, $result['url']);
    }

    public function test_blotato_provider_without_key_fails_loudly_not_silently(): void
    {
        // Rollback path: a workspace with no Blotato key must surface a hard
        // failure (ok=false), proving the Metricool change didn't make Blotato
        // lenient — and that VideoAgent returns the same shape DesignerAgent does.
        config()->set('services.publishing.provider', 'blotato');

        $workspace = new Workspace(['blotato_api_key_handle' => null]);
        $workspace->id = 3;
        $workspace->slug = 'the-bear-hug-enterprise';
        $brand = new Brand();
        $brand->workspace_id = 3;
        $brand->setRelation('workspace', $workspace);

        $result = $this->invokeRehost($this->video(), $brand, 'https://v3b.fal.media/files/x/y.mp4');

        $this->assertFalse($result['ok'], 'Blotato path with no key must fail, not pass the URL through.');
        $this->assertStringContainsString('Blotato', $result['error']);
        $this->assertSame('', $result['url']);
    }
}
