<?php

namespace Tests\Unit;

use App\Jobs\RefreshAccountGrowthJob;
use App\Models\Brand;
use App\Services\Metricool\AccountGrowthService;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            // Followers (timelines) runs first — stub it to 404 so this
            // impressions-focused test stays hermetic and doesn't make real
            // network calls (which would also burn the growth time budget).
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response(['status' => 'BAD_REQUEST'], 404),
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

    public function test_instagram_publishedAt_object_date_buckets_correctly(): void
    {
        // Instagram's date is publishedAt = {dateTime, timezone} (an OBJECT, not a
        // string). The bucketing must dig into .dateTime, not cast the array to a
        // string (which silently lumped everything onto one bogus bucket + warned).
        Http::fake([
            // Stub the timelines (followers) endpoint so this impressions test
            // stays hermetic — see the note in the sibling test above.
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response(['status' => 'BAD_REQUEST'], 404),
            'app.metricool.com/api/v2/analytics/posts/instagram*' => Http::response([
                'data' => [
                    ['publishedAt' => ['dateTime' => '2026-05-10T06:00:00', 'timezone' => 'Europe/Madrid'], 'impressionsTotal' => 400],
                    ['publishedAt' => ['dateTime' => '2026-05-12T09:00:00', 'timezone' => 'Europe/Madrid'], 'impressionsTotal' => 600],
                ],
            ], 200),
            'app.metricool.com/api/v2/analytics/posts/*' => Http::response(['data' => []], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);
        $ig = collect($out['impressions']['networks'])->firstWhere('network', 'instagram');

        $this->assertSame('ok', $ig['status']);
        $this->assertSame(1000, $ig['headline']);
        // Two distinct publish dates on the axis (not one 'unknown' bucket).
        $this->assertContains('2026-05-10', $out['impressions']['axis']);
        $this->assertContains('2026-05-12', $out['impressions']['axis']);
    }

    public function test_linkedin_impressions_are_not_available_not_fabricated(): void
    {
        // LinkedIn has impression_fields=null (post-analytics doesn't expose
        // impressions for this brand; page-impressions API 500s). Per the
        // Truthfulness Contract it must read 'not_available', never a number.
        Http::fake([
            // Stub timelines (followers, runs first) to keep this test hermetic.
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response(['status' => 'BAD_REQUEST'], 404),
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

    /**
     * REGRESSION (prod 500 on /agency/performance, 2026-06-10): the page does up
     * to ~13 SERIAL Metricool calls inside the web request; with the publish-path
     * timeout (30s × retry 2) a few slow networks pushed the request past PHP's
     * 30s max_execution_time → uncatchable fatal → 500. The fix is a wall-clock
     * BUDGET: once exhausted, remaining networks are NOT called — they degrade to
     * an 'error' tile and the page still renders. With the budget pinned to ~0,
     * the deadline trips before the first call, so ZERO HTTP calls go out and
     * every network reads 'error' (never a crash, never a fabricated number).
     */
    public function test_time_budget_halts_calls_and_renders_an_error_tile(): void
    {
        config(['services.metricool.growth_time_budget' => 0]); // deadline = now → trips immediately
        Http::fake([
            // If ANY call leaked through, fail loudly rather than silently pass.
            '*' => Http::response(['data' => []], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);

        // The page still renders a full payload (no exception) …
        $this->assertFalse($out['followers']['has_data']);
        $this->assertFalse($out['impressions']['has_data']);

        // … with every followers network honestly marked 'error' (out of budget),
        // and NOT a fabricated zero or 'ok' tile.
        foreach ($out['followers']['networks'] as $net) {
            $this->assertSame('error', $net['status'], 'followers/' . $net['network'] . " should degrade to 'error' under an exhausted budget");
            $this->assertNull($net['headline']);
        }

        // Impressions: networks with an impression metric degrade to 'error';
        // LinkedIn (impression_fields=null) stays 'not_available' as before — the
        // budget check sits AFTER that structural skip, so it isn't reclassified.
        $li = collect($out['impressions']['networks'])->firstWhere('network', 'linkedin');
        $this->assertSame('not_available', $li['status']);
        $ig = collect($out['impressions']['networks'])->firstWhere('network', 'instagram');
        $this->assertSame('error', $ig['status']);

        // Every ATTEMPTED network errored → reachable=false on both dimensions.
        // This is the signal the Performance view uses to collapse the wall of red
        // tiles into ONE calm "Metricool temporarily unavailable" banner.
        $this->assertFalse($out['followers']['reachable']);
        $this->assertFalse($out['impressions']['reachable']);

        // The whole point: not a single blocking Metricool call was made.
        Http::assertNothingSent();
    }

    /**
     * reachable=true when Metricool ANSWERS — even if some networks aren't
     * connected (not_available) or have no data. "Some networks unconnected" must
     * NOT read as an outage; the per-network tiles render normally, no banner.
     */
    public function test_reachable_true_when_some_networks_answer(): void
    {
        // Instagram followers reports; every other timeline + all post analytics
        // are unconnected/empty. Followers has a real 'ok' → reachable. Impressions
        // returns empty bodies (no_data, not error) → also reachable.
        $this->fakeTimelines([
            'Followers' => ['2026-05-30' => 7],
        ]);
        // fakeTimelines only stubs /timelines; also stub /posts so impressions
        // calls don't hit the network. Empty data => no_data, not error.
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
                return ($q['metric'] ?? '') === 'Followers'
                    ? Http::response($this->series('Followers', ['2026-05-30' => 7]), 200)
                    : Http::response(['status' => 'BAD_REQUEST'], 404);
            },
            'app.metricool.com/api/v2/analytics/posts/*' => Http::response(['data' => []], 200),
        ]);

        $out = (new AccountGrowthService($this->client()))->forBrand($this->brand(), 30);

        $this->assertTrue($out['followers']['reachable'], 'followers answered (IG ok) → reachable');
        $this->assertTrue($out['impressions']['reachable'], 'impressions answered (empty, not errored) → reachable');
    }

    /**
     * Unmapped/unwired brand → empty scaffold → reachable=true (not an outage),
     * so the view shows its connect/empty state, never the outage banner.
     */
    public function test_empty_scaffold_is_reachable_not_an_outage(): void
    {
        $svc = new AccountGrowthService(null); // unconfigured
        $out = $svc->forBrand($this->brand(), 30);

        $this->assertTrue($out['followers']['reachable']);
        $this->assertTrue($out['impressions']['reachable']);
    }

    // ─── Web-safe read path (cachedForBrand) ──────────────────────────────
    // The /agency/performance render calls cachedForBrand(), which must NEVER
    // fan out to Metricool inside the web request — that ~13-serial-call pull
    // pinned the web worker and stalled everyone (prod_web_is_artisan_serve_dev_server).

    public function test_cached_for_brand_cold_cache_warms_in_background_without_calling_metricool(): void
    {
        Http::fake();  // any real call = a regression (would re-pin the worker)
        Queue::fake();

        $brand = $this->brand();
        $brand->id = 7; // dispatch serialises by id

        $out = (new AccountGrowthService($this->client()))->cachedForBrand($brand, 30);

        $this->assertTrue($out['warming'], 'cold cache → warming scaffold');
        $this->assertFalse($out['followers']['has_data']);
        $this->assertTrue($out['configured']);
        Http::assertNothingSent();
        Queue::assertPushed(
            RefreshAccountGrowthJob::class,
            fn (RefreshAccountGrowthJob $j) => $j->brandId === 7 && $j->windowDays === 30
        );
    }

    public function test_cached_for_brand_warm_cache_returns_payload_and_does_not_queue(): void
    {
        Http::fake();
        Queue::fake();

        // Whatever the worker/forBrand() last wrote under the shared key is served
        // verbatim — no recompute, no dispatch.
        $sentinel = ['configured' => true, 'warming' => false, '_sentinel' => 'warm'];
        Cache::put('metricool:growth:6322515:30', $sentinel, 300);

        $out = (new AccountGrowthService($this->client()))->cachedForBrand($this->brand(), 30);

        $this->assertSame($sentinel, $out);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_queue_refresh_is_stampede_guarded(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $brand->id = 7;
        $svc = new AccountGrowthService($this->client());

        // A poll/refresh storm: many calls in the guard window → one job only.
        $svc->queueRefresh($brand, 30);
        $svc->queueRefresh($brand, 30);
        $svc->queueRefresh($brand, 30);

        Queue::assertPushed(RefreshAccountGrowthJob::class, 1);
    }
}
