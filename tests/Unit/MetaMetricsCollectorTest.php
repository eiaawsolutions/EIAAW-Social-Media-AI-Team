<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Meta\MetaGraphClient;
use App\Services\Metrics\MetaMetricsCollector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetaMetricsCollector + MetaGraphClient — first-party IG/FB insights mapping.
 *
 * DB-free (local .env = prod; phpunit.xml leaves DB commented out). In-memory
 * models via setRelation, Http::fake stubs the Graph API.
 */
class MetaMetricsCollectorTest extends TestCase
{
    private function client(): MetaGraphClient
    {
        return new MetaGraphClient(
            accessToken: 'EAA_fake_system_user_token',
            baseUrl: 'https://graph.facebook.com',
            apiVersion: 'v21.0',
        );
    }

    private function makePost(string $platform, string $mediaId): ScheduledPost
    {
        $draft = new Draft();
        $draft->platform = $platform;

        $post = new ScheduledPost();
        $post->id = 4242;
        $post->brand_id = 88;
        $post->status = 'published';
        $post->platform_post_id = $mediaId;
        $post->setRelation('draft', $draft);

        return $post;
    }

    public function test_maps_meta_insights_data_array_to_columns(): void
    {
        Http::fake([
            '*/v21.0/17900000000000000/insights*' => Http::response([
                'data' => [
                    ['name' => 'reach', 'period' => 'lifetime', 'values' => [['value' => 3120]]],
                    ['name' => 'impressions', 'period' => 'lifetime', 'values' => [['value' => 4000]]],
                    ['name' => 'likes', 'period' => 'lifetime', 'values' => [['value' => 152]]],
                    ['name' => 'comments', 'period' => 'lifetime', 'values' => [['value' => 13]]],
                    ['name' => 'shares', 'period' => 'lifetime', 'values' => [['value' => 7]]],
                    ['name' => 'saved', 'period' => 'lifetime', 'values' => [['value' => 21]]],
                ],
            ], 200),
        ]);

        $post = $this->makePost('instagram', '17900000000000000');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        $this->assertSame('metrics', $out['status']);
        $this->assertSame('meta_graph', $out['source']);
        $this->assertSame(3120, $out['reach']);
        $this->assertSame(4000, $out['impressions']);
        $this->assertSame(152, $out['likes']);
        $this->assertSame(13, $out['comments']);
        $this->assertSame(7, $out['shares']);
        $this->assertSame(21, $out['saves']); // Meta 'saved' → our 'saves'
        // (152+13+7+21)/4000 = 0.04825
        $this->assertEqualsWithDelta(0.0483, $out['engagement_rate'], 0.0001);
    }

    public function test_empty_data_array_is_no_metrics_yet_not_zeros(): void
    {
        Http::fake([
            '*/insights*' => Http::response(['data' => []], 200),
        ]);

        $post = $this->makePost('instagram', '17911111111111111');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        $this->assertSame('no_metrics_yet', $out['status']);
        $this->assertSame('meta_graph', $out['source']);
        $this->assertArrayNotHasKey('likes', $out); // no fabricated zeros
    }

    public function test_permission_error_returns_no_metrics_yet(): void
    {
        Http::fake([
            '*/insights*' => Http::response([
                'error' => ['code' => 10, 'message' => 'Application does not have permission'],
            ], 403),
        ]);

        $post = $this->makePost('facebook', '99999');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        // Surfaces as no-data (logged loudly inside) rather than crashing.
        $this->assertSame('no_metrics_yet', $out['status']);
    }

    public function test_non_meta_platform_is_skipped(): void
    {
        Http::fake();
        $post = $this->makePost('tiktok', '123');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        $this->assertSame([], $out);
        Http::assertNothingSent();
    }

    public function test_missing_platform_post_id_is_skipped(): void
    {
        Http::fake();
        $post = $this->makePost('instagram', '');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        $this->assertSame([], $out);
        Http::assertNothingSent();
    }

    public function test_400_unsupported_metric_retries_with_safe_subset(): void
    {
        // First call (full metric set) 400s; client retries with the safe
        // subset and that 200s. Collector should still return real metrics.
        Http::fakeSequence('*/insights*')
            ->push(['error' => ['code' => 100, 'message' => 'Unsupported metric views']], 400)
            ->push([
                'data' => [
                    ['name' => 'reach', 'values' => [['value' => 500]]],
                    ['name' => 'likes', 'values' => [['value' => 40]]],
                ],
            ], 200);

        $post = $this->makePost('instagram', '17922222222222222');
        $out = (new MetaMetricsCollector($this->client()))->collect($post);

        $this->assertSame('metrics', $out['status']);
        $this->assertSame(500, $out['reach']);
        $this->assertSame(40, $out['likes']);
    }
}
