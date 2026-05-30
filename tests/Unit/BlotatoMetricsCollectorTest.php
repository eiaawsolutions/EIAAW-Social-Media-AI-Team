<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Metrics\BlotatoMetricsCollector;
use App\Services\Secrets\InfisicalResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BlotatoMetricsCollector — the analytics-pull mapping invariants.
 *
 * DB-free by design (this project's local .env points at prod; phpunit.xml
 * leaves DB connections commented out). We build in-memory models with
 * setRelation() and never call save(); Http::fake() stubs every Blotato call.
 *
 * What these assert:
 *   - postUrl → publishedPostId resolution off /v2/published-posts
 *   - string metric values cast to int onto the typed PostMetric columns
 *   - the dormant 404 case returns 'no_metrics_yet' WITHOUT fabricating zeros
 *   - guard rails: no platform_post_url / no workspace → empty (no snapshot)
 */
class BlotatoMetricsCollectorTest extends TestCase
{
    /** Bind a resolver that returns a valid blt_ key so forWorkspace() builds. */
    private function fakeResolver(): void
    {
        $this->app->instance(InfisicalResolver::class, new class extends InfisicalResolver {
            public function __construct()
            {
                parent::__construct([]);
            }

            public function resolve(string $handle): string
            {
                return 'blt_fake_metrics_key';
            }
        });
    }

    /** In-memory ScheduledPost wired to a brand→workspace and a draft. */
    private function makePost(string $platform, string $postUrl): ScheduledPost
    {
        $ws = new Workspace();
        $ws->id = 501;
        $ws->slug = 'metrics-ws';
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_501';

        $brand = new Brand();
        $brand->id = 9001;
        $brand->setRelation('workspace', $ws);

        $draft = new Draft();
        $draft->platform = $platform;

        $post = new ScheduledPost();
        $post->id = 7777;
        $post->brand_id = $brand->id;
        $post->status = 'published';
        $post->blotato_post_id = 'submission-abc';   // submission id (NOT the published id)
        $post->platform_post_url = $postUrl;
        $post->setRelation('brand', $brand);
        $post->setRelation('draft', $draft);

        return $post;
    }

    public function test_maps_string_metrics_to_int_after_resolving_published_id(): void
    {
        $this->fakeResolver();

        Http::fake([
            // Resolution list — our postUrl matches the second item.
            '*/v2/published-posts*' => Http::response([
                'items' => [
                    ['id' => '111', 'platform' => 'instagram', 'postUrl' => 'https://instagram.com/p/OTHER/'],
                    ['id' => '4397066', 'platform' => 'instagram', 'postUrl' => 'https://www.instagram.com/p/DY8u01sjt4e/'],
                ],
            ], 200),
            // Analytics — Blotato sends counters as STRINGS.
            '*/v2/posts/4397066/analytics' => Http::response([
                'publishedPostId' => '4397066',
                'platform' => 'instagram',
                'lastFetchedAt' => '2026-05-30T10:00:00.000Z',
                'metrics' => [
                    'likesCount' => '152',
                    'commentsCount' => '13',
                    'sharesCount' => '7',
                    'savesCount' => '21',
                    'impressionsCount' => '4000',
                    'reachCount' => '3120',
                    'viewsCount' => '5300',
                ],
            ], 200),
        ]);

        $post = $this->makePost('instagram', 'https://www.instagram.com/p/DY8u01sjt4e/');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame('metrics', $out['status']);
        $this->assertSame('blotato_analytics', $out['source']);
        $this->assertSame('4397066', $out['blotato_published_id']);
        // Cast to int, not left as strings.
        $this->assertSame(152, $out['likes']);
        $this->assertSame(13, $out['comments']);
        $this->assertSame(7, $out['shares']);
        $this->assertSame(21, $out['saves']);
        $this->assertSame(4000, $out['impressions']);
        $this->assertSame(3120, $out['reach']);
        $this->assertSame(5300, $out['video_views']);
        // engagement_rate = (152+13+7+21)/4000 = 0.0483
        $this->assertEqualsWithDelta(0.0483, $out['engagement_rate'], 0.0001);
    }

    public function test_url_match_is_case_and_trailing_slash_insensitive(): void
    {
        $this->fakeResolver();

        Http::fake([
            '*/v2/published-posts*' => Http::response([
                'items' => [
                    ['id' => '900', 'platform' => 'linkedin', 'postUrl' => 'https://LinkedIn.com/feed/update/urn:li:share:123/'],
                ],
            ], 200),
            '*/v2/posts/900/analytics' => Http::response([
                'metrics' => ['likesCount' => '5'],
                'lastFetchedAt' => '2026-05-30T10:00:00.000Z',
            ], 200),
        ]);

        // Our stored URL differs only by case + trailing slash.
        $post = $this->makePost('linkedin', 'https://linkedin.com/feed/update/urn:li:share:123');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame('metrics', $out['status']);
        $this->assertSame(5, $out['likes']);
    }

    public function test_dormant_404_returns_no_metrics_yet_without_fabricating_zeros(): void
    {
        $this->fakeResolver();

        Http::fake([
            '*/v2/published-posts*' => Http::response([
                'items' => [
                    ['id' => '4397066', 'platform' => 'instagram', 'postUrl' => 'https://www.instagram.com/p/DY8u01sjt4e/'],
                ],
            ], 200),
            // The reality today: analytics backend unshipped.
            '*/v2/posts/4397066/analytics' => Http::response(['message' => 'Analytics not available'], 404),
        ]);

        $post = $this->makePost('instagram', 'https://www.instagram.com/p/DY8u01sjt4e/');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame('no_metrics_yet', $out['status']);
        $this->assertSame('4397066', $out['blotato_published_id']);
        // Crucially: no counter keys present → job writes NULLs, not zeros.
        $this->assertArrayNotHasKey('likes', $out);
        $this->assertArrayNotHasKey('impressions', $out);
    }

    public function test_200_with_empty_metrics_block_is_also_no_metrics_yet(): void
    {
        $this->fakeResolver();

        Http::fake([
            '*/v2/published-posts*' => Http::response([
                'items' => [['id' => '55', 'platform' => 'threads', 'postUrl' => 'https://threads.net/p/abc']],
            ], 200),
            '*/v2/posts/55/analytics' => Http::response([
                'publishedPostId' => '55',
                'lastFetchedAt' => '2026-05-30T10:00:00.000Z',
                'metrics' => [],
            ], 200),
        ]);

        $post = $this->makePost('threads', 'https://threads.net/p/abc');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame('no_metrics_yet', $out['status']);
    }

    public function test_url_not_in_published_list_yields_empty_no_snapshot(): void
    {
        $this->fakeResolver();

        Http::fake([
            '*/v2/published-posts*' => Http::response([
                'items' => [['id' => '1', 'platform' => 'instagram', 'postUrl' => 'https://instagram.com/p/DIFFERENT/']],
            ], 200),
        ]);

        $post = $this->makePost('instagram', 'https://instagram.com/p/MINE/');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame([], $out);
    }

    public function test_post_with_no_platform_url_yields_empty(): void
    {
        // No HTTP should even be attempted — nothing to match against.
        Http::fake(); // any call would record; we assert none below
        $post = $this->makePost('instagram', '');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame([], $out);
        Http::assertNothingSent();
    }

    public function test_x_platform_maps_to_twitter_in_published_posts_query(): void
    {
        $this->fakeResolver();

        Http::fake([
            '*/v2/published-posts*' => Http::response(['items' => [
                ['id' => '42', 'platform' => 'twitter', 'postUrl' => 'https://x.com/u/status/42'],
            ]], 200),
            '*/v2/posts/42/analytics' => Http::response([
                'metrics' => ['likesCount' => '9', 'twitterRetweetsCount' => '3'],
                'lastFetchedAt' => '2026-05-30T10:00:00.000Z',
            ], 200),
        ]);

        $post = $this->makePost('x', 'https://x.com/u/status/42');
        $out = app(BlotatoMetricsCollector::class)->collect($post);

        $this->assertSame('metrics', $out['status']);
        $this->assertSame(9, $out['likes']);
        $this->assertSame(3, $out['shares']); // twitterRetweetsCount → shares alias

        // The published-posts query must have filtered platform=twitter, not x.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/published-posts')
                && str_contains($request->url(), 'platform=twitter');
        });
    }
}
