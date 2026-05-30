<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Metrics\MetricsProviderRouter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetricsProviderRouter — per-post provider selection seam (post-Blotato
 * decommission). Two providers remain:
 *   - Meta Graph: HQ-owned IG/FB with a System User token.
 *   - Metricool: any brand mapped to a Metricool blogId + integration configured.
 * A post matching neither yields an empty result (no snapshot written).
 *
 * DB-free: in-memory models + Http::fake spies. Meta hits graph.facebook.com;
 * Metricool hits app.metricool.com.
 */
class MetricsProviderRouterTest extends TestCase
{
    private function makePost(string $platform, string $workspaceType, ?string $metricoolBlogId = null): ScheduledPost
    {
        $ws = new Workspace();
        $ws->id = 1;
        $ws->slug = 'hq';
        $ws->type = $workspaceType; // 'internal' = HQ

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
        $post->blotato_post_id = 'sub-1'; // generic provider submission id
        $post->platform_post_id = '17900000000000000';
        $post->platform_post_url = 'https://www.instagram.com/p/ABC/';
        $post->setRelation('brand', $brand);
        $post->setRelation('draft', $draft);

        return $post;
    }

    private function router(): MetricsProviderRouter
    {
        return new MetricsProviderRouter();
    }

    public function test_hq_instagram_with_token_routes_to_meta(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [['name' => 'reach', 'values' => [['value' => 99]]]],
            ], 200),
        ]);

        $out = $this->router()->collect($this->makePost('instagram', 'internal'));

        $this->assertSame('meta_graph', $out['source']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_customer_instagram_without_metricool_yields_empty(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        // Customer workspace, brand not on Metricool → no provider applies.
        $out = $this->router()->collect($this->makePost('instagram', 'customer'));

        $this->assertSame([], $out);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_hq_tiktok_without_metricool_yields_empty(): void
    {
        config(['services.meta.graph.system_user_token' => 'EAA_fake_hq_token']);

        Http::fake();

        // TikTok is not a Meta platform AND brand has no Metricool blogId → empty.
        $out = $this->router()->collect($this->makePost('tiktok', 'internal'));

        $this->assertSame([], $out);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'app.metricool.com'));
    }

    public function test_brand_with_metricool_blog_id_routes_to_metricool(): void
    {
        config([
            'services.metricool.api_token' => 'mc_real_token',
            'services.metricool.user_id' => 4872275,
            'services.meta.graph.system_user_token' => '',
        ]);

        Http::fake([
            'app.metricool.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->router()->collect($this->makePost('tiktok', 'customer', '6322515'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'app.metricool.com')
            && str_contains($r->url(), 'blogId=6322515'));
    }

    public function test_metricool_mapped_brand_yields_empty_when_unconfigured(): void
    {
        // Brand HAS a blogId but the integration is NOT configured → no provider.
        config([
            'services.metricool.api_token' => '',
            'services.metricool.user_id' => 0,
            'services.meta.graph.system_user_token' => '',
        ]);

        Http::fake();

        $out = $this->router()->collect($this->makePost('tiktok', 'customer', '6322515'));

        $this->assertSame([], $out);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'app.metricool.com'));
    }
}
