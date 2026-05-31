<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Services\Metricool\AccountGrowthService;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AccountGrowthService — the account-growth dashboard brain (followers +
 * impressions over time, per network), from Metricool's account timeline API
 * (GET /v2/analytics/timelines, subject=account). Endpoint + response shape
 * verified live 2026-05-31; per-network metric names match the Metricool UI.
 *
 * DB-free: an in-memory Brand (new Brand([...]), no save()) carries the blogId,
 * matching the local-.env-points-at-prod caveat. Network calls are Http::fake,
 * keyed off the metric+network query params (all hit the same path). We assert
 * the Truthfulness Contract end-to-end:
 *   - A network that 400/404/500s → status 'not_available' (not connected / no
 *     metric for it), never a fabricated zero tile.
 *   - Missing daily readings stay null on the shared axis — never 0.
 *   - Followers headline = latest level; change = latest − first (net new).
 *   - Impressions headline = sum over the window (a flow, not a stock).
 *   - TikTok and Threads ARE real charted networks now (the correct endpoint
 *     covers them — the old "no API data" was the wrong legacy endpoint).
 */
class AccountGrowthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the service caches by blogId+window; isolate each test
    }

    private function client(): MetricoolClient
    {
        return new MetricoolClient(
            apiToken: 'mc_test_token',
            userId: 4242,
            baseUrl: 'https://app.metricool.com/api',
            timeout: 30,
        );
    }

    private function brand(int $blogId = 6322515): Brand
    {
        return new Brand([
            'name' => 'EIAAW',
            'metricool_blog_id' => $blogId,
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    /** Build a {data:[{metric,values:[{dateTime,value}]}]} body for a metric. */
    private function series(string $metric, array $dateValue): array
    {
        $values = [];
        foreach ($dateValue as $d => $v) {
            $values[] = ['dateTime' => $d . 'T12:00:00+0000', 'value' => $v];
        }
        return ['data' => [['metric' => $metric, 'values' => $values]]];
    }

    /**
     * Fake /v2/analytics/timelines, returning a per-metric body when the
     * request's metric query param matches; otherwise a 404 (not connected).
     *
     * @param  array<string, array<string,int|float>>  $byMetric  metric => [date => value]
     */
    private function fakeTimelines(array $byMetric): void
    {
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => function ($request) use ($byMetric) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
                $metric = $q['metric'] ?? '';
                if (array_key_exists($metric, $byMetric)) {
                    return Http::response($this->series($metric, $byMetric[$metric]), 200);
                }
                return Http::response(['status' => 'BAD_REQUEST'], 404);
            },
        ]);
    }

    public function test_unconfigured_client_returns_scaffold_without_calling_metricool(): void
    {
        Http::fake();
        $svc = new AccountGrowthService(null);

        $out = $svc->forBrand($this->brand(), 30);

        $this->assertFalse($out['configured']);
        $this->assertSame(0, $out['followers']['total']);
        $this->assertFalse($out['followers']['has_data']);
        Http::assertNothingSent();
    }

    public function test_followers_headline_is_latest_and_change_is_net_new(): void
    {
        // Only Instagram's Followers metric reports; everything else 404s.
        $this->fakeTimelines([
            'Followers' => ['2026-05-01' => 100, '2026-05-02' => 108, '2026-05-03' => 120],
        ]);

        // NOTE: LinkedIn + X also use the 'Followers' metric name, so they'd
        // match the same fake. Scope the assertion to Instagram, which is the
        // network we're reasoning about; the point is the stock/flow math.
        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $dim = $out['followers'];

        $ig = collect($dim['networks'])->firstWhere('network', 'instagram');
        $this->assertSame('ok', $ig['status']);
        $this->assertSame(120, $ig['headline']);   // latest level
        $this->assertSame(20, $ig['change']);      // 120 − 100 net new
        $this->assertTrue($dim['has_data']);
    }

    public function test_impressions_sum_per_post_analytics_not_timelines(): void
    {
        // Impressions come from GET /v2/analytics/posts/{network}, summed per post
        // on its publish date — NOT the account timelines endpoint. Instagram's
        // impression field is impressionsTotal (verified in metricool-field-map).
        Http::fake([
            'app.metricool.com/api/v2/analytics/posts/instagram*' => Http::response([
                'data' => [
                    ['dateTime' => '2026-05-01T10:00:00+0000', 'impressionsTotal' => 500],
                    ['dateTime' => '2026-05-01T18:00:00+0000', 'impressionsTotal' => 300],
                    ['dateTime' => '2026-05-03T09:00:00+0000', 'impressionsTotal' => 700],
                ],
            ], 200),
            // every other network's post analytics → empty
            'app.metricool.com/api/v2/analytics/posts/*' => Http::response(['data' => []], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $ig = collect($out['impressions']['networks'])->firstWhere('network', 'instagram');

        $this->assertSame('ok', $ig['status']);
        $this->assertSame(1500, $ig['headline']);          // 500+300+700 summed (flow)
        // Two posts on 2026-05-01 bucket onto the same day = 800.
        $this->assertSame([800, 700], array_values(array_filter($ig['series'], fn ($v) => $v !== null)));
    }

    public function test_linkedin_impressions_are_not_available_not_fabricated(): void
    {
        // LinkedIn has impression_fields=null (post-analytics doesn't expose
        // impressions for this brand; page-impressions API 500s). Per the
        // Truthfulness Contract it must read 'not_available', never a number.
        Http::fake([
            'app.metricool.com/api/v2/analytics/posts/*' => Http::response(['data' => []], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $li = collect($out['impressions']['networks'])->firstWhere('network', 'linkedin');

        $this->assertSame('not_available', $li['status']);
        $this->assertNull($li['headline']);
    }

    public function test_unconnected_network_is_not_available_not_a_zero(): void
    {
        // YouTube reports; the rest 404. YouTube uses 'totalSubscribers'.
        $this->fakeTimelines([
            'totalSubscribers' => ['2026-05-30' => 1],
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $dim = $out['followers'];

        $yt = collect($dim['networks'])->firstWhere('network', 'youtube');
        $this->assertSame('ok', $yt['status']);
        $this->assertSame(1, $yt['headline']);

        // Facebook uses 'Follows' which we didn't fake → 404 → not_available.
        $fb = collect($dim['networks'])->firstWhere('network', 'facebook');
        $this->assertSame('not_available', $fb['status']);
        $this->assertNull($fb['headline']);
    }

    public function test_tiktok_and_threads_are_real_charted_networks(): void
    {
        // The correct endpoint covers TikTok + Threads (followers_count).
        $this->fakeTimelines([
            'followers_count' => ['2026-05-29' => 3, '2026-05-30' => 3],
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $networks = collect($out['followers']['networks']);

        $tt = $networks->firstWhere('network', 'tiktok');
        $th = $networks->firstWhere('network', 'threads');
        $this->assertSame('ok', $tt['status']);
        $this->assertSame(3, $tt['headline']);
        $this->assertSame('ok', $th['status']);
        $this->assertSame(3, $th['headline']);

        // NETWORKS_WITHOUT_API is now empty — nothing listed as unsupported.
        $this->assertSame([], $out['unsupported']);
    }

    public function test_missing_daily_readings_stay_null_on_shared_axis(): void
    {
        // IG (Followers) reports 3 days; YouTube (totalSubscribers) only the
        // middle day. On the merged axis YouTube's first/last cells must be null.
        $this->fakeTimelines([
            'Followers' => ['2026-05-01' => 100, '2026-05-02' => 101, '2026-05-03' => 102],
            'totalSubscribers' => ['2026-05-02' => 40],
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $dim = $out['followers'];

        $this->assertSame(['2026-05-01', '2026-05-02', '2026-05-03'], $dim['axis']);

        $yt = collect($dim['networks'])->firstWhere('network', 'youtube');
        $this->assertSame([null, 40, null], $yt['series']); // gaps are null, not 0
    }

    public function test_calls_use_v2_timelines_with_subject_account(): void
    {
        $this->fakeTimelines(['Followers' => ['2026-05-30' => 7]]);

        (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/analytics/timelines')
            && str_contains($r->url(), 'subject=account')
            && str_contains($r->url(), 'blogId=6322515'));

        // The dead legacy endpoint must never be called.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/stats/timeline/'));
    }

    public function test_unmapped_brand_returns_empty_dimensions_without_calling_metricool(): void
    {
        Http::fake();
        $brand = new Brand(['name' => 'Unmapped', 'timezone' => 'UTC']); // no blogId

        $out = (new AccountGrowthService($this->client()))->forBrand($brand, 30);

        $this->assertNull($out['blog_id']);
        $this->assertFalse($out['followers']['has_data']);
        Http::assertNothingSent();
    }
}
