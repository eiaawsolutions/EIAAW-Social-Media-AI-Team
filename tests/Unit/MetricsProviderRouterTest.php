<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Metrics\BlotatoMetricsCollector;
use App\Services\Metrics\MetricsProviderRouter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetricsProviderRouter — the per-post provider selection seam.
 *
 * Asserts the routing decision, not the wire calls: a post routes to Meta
 * ONLY when platform is IG/FB AND the workspace is internal (HQ) AND a System
 * User token is configured. Every other case falls back to Blotato.
 *
 * We detect which provider ran via Http::fake spies — Meta hits
 * graph.facebook.com; Blotato hits backend.blotato.com. DB-free.
 */
class MetricsProviderRouterTest extends TestCase
{
    private function makePost(string $platform, string $workspaceType, ?string $metricoolBlogId = null): ScheduledPost
    {
        $ws = new Workspace();
        $ws->id = 1;
        $ws->slug = 'hq';
        $ws->type = $workspaceType; // 'internal' = HQ
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_1';

        $brand = new Brand();
        $brand->id = 10;
        $brand->metricool_blog_id = $metricoolBlogId; // null = not mapped to Metricool
        $brand->setRelation('workspace', $ws);

        $draft = new Draft();
        $draft->platform = $platform;

        $post = new ScheduledPost();
        $post->id = 500;
        $post->brand_id = 10;
        $post->status = 'published';
        $post->blotato_post_id = 'sub-1';
        $post->platform_post_id = '17900000000000000';
        $post->platform_post_url = 'https://www.instagram.com/p/ABC/';
        $post->setRelation('brand', $brand);
        $post->setRelation('draft', $draft);

        return $post;
    }

    private function router(): MetricsProviderRouter
    {
        // Real Blotato collector; its calls are caught by Http::fake.
        return new MetricsProviderRouter(app(BlotatoMetricsCollector::class));
    }

    public function test_hq_instagram_with_token_routes_to_meta(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [['name' => 'reach', 'values' => [['value' => 99]]]],
            ], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        $out = $this->router()->collect($this->makePost('instagram', 'internal'));

        $this->assertSame('meta_graph', $out['source']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_customer_instagram_falls_back_to_blotato_even_with_token(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        // type != internal → customer workspace → Meta must NOT be used.
        $out = $this->router()->collect($this->makePost('instagram', 'customer'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_hq_instagram_without_token_falls_back_to_blotato(): void
    {
        config(['services.meta.graph.system_user_token' => '']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        $out = $this->router()->collect($this->makePost('instagram', 'internal'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_hq_tiktok_falls_back_to_blotato(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        // TikTok is not a Meta platform AND brand has no Metricool blogId →
        // Blotato regardless of token.
        $out = $this->router()->collect($this->makePost('tiktok', 'internal'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'app.metricool.com'));
    }

    public function test_brand_with_metricool_blog_id_routes_to_metricool_not_blotato(): void
    {
        // Metricool configured + brand mapped → Metricool wins over Blotato.
        config([
            'services.metricool.api_token' => 'mc_real_token',
            'services.metricool.user_id' => 4872275,
            'services.meta.graph.system_user_token' => '', // no Meta path
        ]);

        Http::fake([
            'app.metricool.com/*' => Http::response(['data' => []], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        // TikTok (non-Meta) customer post whose brand has a Metricool blogId.
        $this->router()->collect($this->makePost('tiktok', 'customer', '6322515'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'app.metricool.com')
            && str_contains($r->url(), 'blogId=6322515'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'backend.blotato.com'));
    }

    public function test_metricool_mapped_brand_still_falls_back_when_unconfigured(): void
    {
        // Brand HAS a blogId but the integration is NOT configured (no token)
        // → must NOT hit Metricool; falls back to Blotato.
        config([
            'services.metricool.api_token' => '',
            'services.metricool.user_id' => 0,
            'services.meta.graph.system_user_token' => '',
        ]);

        Http::fake([
            'app.metricool.com/*' => Http::response(['data' => []], 200),
            'backend.blotato.com/*' => Http::response(['items' => []], 200),
        ]);

        $this->router()->collect($this->makePost('tiktok', 'customer', '6322515'));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'app.metricool.com'));
    }
}
