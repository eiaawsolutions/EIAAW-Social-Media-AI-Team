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
 * impressions over time, per network), from Metricool's /stats/timeline API.
 *
 * DB-free: an in-memory Brand (new Brand([...]), no save()) carries the blogId,
 * matching the local-.env-points-at-prod caveat. Network calls are Http::fake.
 * We assert the Truthfulness Contract end-to-end:
 *   - TikTok/Threads have NO Metricool timeline metric → status 'no_api_data'
 *     and they appear in the 'unsupported' list, never as a fabricated series.
 *   - A network that 404s → status 'not_available' (not connected / not on plan).
 *   - Missing daily readings stay null on the shared axis — never 0.
 *   - Followers headline = latest level; change = latest − first (net new).
 *   - Impressions headline = sum over the window (a flow, not a stock).
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
        Http::fake([
            // Only Instagram reports; others 404 (not connected).
            'app.metricool.com/api/stats/timeline/igFollowers*' => Http::response([
                ['20260501', '100'], ['20260502', '108'], ['20260503', '120'],
            ], 200),
            'app.metricool.com/api/stats/timeline/*' => Http::response(['message' => 'na'], 404),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $dim = $out['followers'];

        $ig = collect($dim['networks'])->firstWhere('network', 'instagram');
        $this->assertSame('ok', $ig['status']);
        $this->assertSame(120, $ig['headline']);     // latest level
        $this->assertSame(20, $ig['change']);        // 120 − 100 net new
        $this->assertSame(120, $dim['total']);       // sum of network headlines
        $this->assertTrue($dim['has_data']);

        // A 404 network is honest 'not_available', never a zero tile.
        $li = collect($dim['networks'])->firstWhere('network', 'linkedin');
        $this->assertSame('not_available', $li['status']);
        $this->assertNull($li['headline']);
    }

    public function test_impressions_headline_is_window_sum(): void
    {
        Http::fake([
            'app.metricool.com/api/stats/timeline/igimpressions*' => Http::response([
                ['20260501', '500'], ['20260502', '700'], ['20260503', '300'],
            ], 200),
            'app.metricool.com/api/stats/timeline/*' => Http::response(['message' => 'na'], 404),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $ig = collect($out['impressions']['networks'])->firstWhere('network', 'instagram');

        $this->assertSame('ok', $ig['status']);
        $this->assertSame(1500, $ig['headline']);  // 500+700+300 summed (flow)
    }

    public function test_tiktok_and_threads_are_no_api_data_and_listed_unsupported(): void
    {
        Http::fake([
            'app.metricool.com/api/stats/timeline/*' => Http::response([['20260501', '10']], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);

        // They never appear as a charted network …
        $networks = collect($out['followers']['networks'])->pluck('network');
        $this->assertFalse($networks->contains('tiktok'));
        $this->assertFalse($networks->contains('threads'));

        // … they appear in the honest 'unsupported' list instead.
        $unsupported = collect($out['unsupported'])->pluck('network');
        $this->assertTrue($unsupported->contains('tiktok'));
        $this->assertTrue($unsupported->contains('threads'));

        // And we NEVER hit a tiktok/threads timeline endpoint (no metric exists).
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'timeline/tt')
            || str_contains($r->url(), 'timeline/threads'));
    }

    public function test_missing_daily_readings_stay_null_on_shared_axis(): void
    {
        // IG reports 3 days; LinkedIn reports only the middle day. On the merged
        // axis LinkedIn's first/last cells must be null (gap), not 0.
        Http::fake([
            'app.metricool.com/api/stats/timeline/igFollowers*' => Http::response([
                ['20260501', '100'], ['20260502', '101'], ['20260503', '102'],
            ], 200),
            'app.metricool.com/api/stats/timeline/inFollowers*' => Http::response([
                ['20260502', '40'],
            ], 200),
            'app.metricool.com/api/stats/timeline/*' => Http::response(['message' => 'na'], 404),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $dim = $out['followers'];

        $this->assertSame(['2026-05-01', '2026-05-02', '2026-05-03'], $dim['axis']);

        $li = collect($dim['networks'])->firstWhere('network', 'linkedin');
        $this->assertSame([null, 40, null], $li['series']); // gaps are null, not 0
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
