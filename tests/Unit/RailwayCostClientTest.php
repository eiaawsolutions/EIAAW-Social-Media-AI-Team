<?php

namespace Tests\Unit;

use App\Services\Monitoring\RailwayCostClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RailwayCostClient contracts.
 *
 * DB-free (local .env points at prod). HTTP is faked, so no real Railway call
 * is made. Locks: the disabled/unconfigured short-circuits, the GraphQL-error
 * path returns null (so the monitor falls back rather than reporting a bogus
 * cost), and the resource-quantity → USD pricing math is faithful.
 *
 * UNIT-BASIS REGRESSION (2026-05-30): Railway's usage API returns the
 * time-based measurements (CPU, memory, disk, backup) in RESOURCE-MINUTES, so
 * disk/backup price per GB-MINUTE. A prior version priced them per GB-MONTH
 * and overstated the Railway line ~30x ($11k vs the real ~$5). The
 * prod-quantity test below is the guard against that ever returning.
 */
class RailwayCostClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // avoid a cached result leaking between cases

        config()->set('costs.railway.enabled', true);
        config()->set('costs.railway.token', 'tok_live_example');
        config()->set('costs.railway.project_id', 'proj-123');
        config()->set('costs.railway.endpoint', 'https://backboard.railway.com/graphql/v2');
        config()->set('costs.railway.cache_ttl', 0); // don't cache in tests
        // The shipped per-minute prices (disk + backup per GB-MINUTE, not month).
        config()->set('costs.railway.unit_prices_usd', [
            'cpu_vcpu_minute' => 0.000463,
            'memory_gb_minute' => 0.000231,
            'disk_gb_minute' => 0.00000385,
            'network_tx_gb' => 0.05,
            'backup_gb_minute' => 0.00000385,
        ]);
    }

    public function test_returns_null_when_disabled(): void
    {
        config()->set('costs.railway.enabled', false);
        Http::fake();

        $this->assertNull((new RailwayCostClient)->cost());
        Http::assertNothingSent(); // never even hits the network
    }

    public function test_returns_null_when_token_is_an_unresolved_handle(): void
    {
        // A secret:// handle that never resolved must NOT be sent upstream.
        config()->set('costs.railway.token', 'secret://eiaaw-smt-prod/prod/RAILWAY_API_TOKEN');
        Http::fake();

        $this->assertNull((new RailwayCostClient)->cost());
        Http::assertNothingSent();
    }

    public function test_returns_null_on_graphql_errors(): void
    {
        // Railway returns 200 + errors[] on auth failures — must be treated as
        // a failure (fall back), not a $0 cost.
        Http::fake([
            '*' => Http::response(['errors' => [['message' => 'Not Authorized']]], 200),
        ]);

        $this->assertNull((new RailwayCostClient)->cost());
    }

    public function test_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertNull((new RailwayCostClient)->cost());
    }

    public function test_prices_usage_rows_into_usd_on_per_minute_basis(): void
    {
        // usage: 1000 vCPU-min, 2000 GB-min mem, 600 GB-min disk, 5 GB egress.
        //   cpu  = 1000 × 0.000463   = 0.4630
        //   mem  = 2000 × 0.000231   = 0.4620
        //   disk =  600 × 0.00000385 = 0.00231
        //   net  =    5 × 0.05       = 0.2500
        //   total = 1.17731 → round 1.18
        // estimatedUsage doubles cpu/mem to project a higher end-of-cycle.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'usage' => [
                        ['measurement' => 'CPU_USAGE', 'value' => 1000],
                        ['measurement' => 'MEMORY_USAGE_GB', 'value' => 2000],
                        ['measurement' => 'DISK_USAGE_GB', 'value' => 600],
                        ['measurement' => 'NETWORK_TX_GB', 'value' => 5],
                    ],
                    'estimatedUsage' => [
                        ['measurement' => 'CPU_USAGE', 'estimatedValue' => 2000],
                        ['measurement' => 'MEMORY_USAGE_GB', 'estimatedValue' => 4000],
                        ['measurement' => 'DISK_USAGE_GB', 'estimatedValue' => 600],
                        ['measurement' => 'NETWORK_TX_GB', 'estimatedValue' => 5],
                    ],
                ],
            ], 200),
        ]);

        $cost = (new RailwayCostClient)->cost();

        $this->assertNotNull($cost);
        $this->assertSame('railway-api', $cost['source']);
        $this->assertSame(1.18, $cost['current_usd']);
        // estimated: cpu 0.926 + mem 0.924 + disk 0.00231 + net 0.25 = 2.10231 → 2.10
        $this->assertSame(2.10, $cost['estimated_usd']);
    }

    public function test_real_prod_quantities_price_to_a_realistic_total_not_11k(): void
    {
        // The ACTUAL estimatedUsage quantities pulled live from prod 2026-05-30.
        // With the correct per-minute basis these price to ~$5.22. The bug we
        // are guarding against priced disk+backup per GB-MONTH → ~$11,196.
        $raw = [
            ['measurement' => 'CPU_USAGE', 'estimatedValue' => 1477.379441],
            ['measurement' => 'MEMORY_USAGE_GB', 'estimatedValue' => 17237.951169],
            ['measurement' => 'NETWORK_TX_GB', 'estimatedValue' => 5.284251],
            ['measurement' => 'DISK_USAGE_GB', 'estimatedValue' => 52351.074745],
            ['measurement' => 'BACKUP_USAGE_GB', 'estimatedValue' => 22260.97166],
        ];

        Http::fake([
            '*' => Http::response(['data' => ['estimatedUsage' => $raw, 'usage' => $raw]], 200),
        ]);

        $cost = (new RailwayCostClient)->cost();

        $this->assertNotNull($cost);
        $this->assertEqualsWithDelta(5.22, $cost['estimated_usd'], 0.10);
        // Hard ceiling: anything over $50 means a time-based measurement is
        // mis-priced again (the disk/backup-per-month regression).
        $this->assertLessThan(50, $cost['estimated_usd']);
    }

    public function test_disk_quantity_is_priced_per_minute_not_per_month(): void
    {
        // 52,351 GB-MINUTES of disk. Per-month ($0.15) would be ~$7,852; the
        // correct per-minute basis is cents.
        Http::fake([
            '*' => Http::response(['data' => [
                'estimatedUsage' => [['measurement' => 'DISK_USAGE_GB', 'estimatedValue' => 52351.074745]],
                'usage' => [['measurement' => 'DISK_USAGE_GB', 'value' => 52351.074745]],
            ]], 200),
        ]);

        $cost = (new RailwayCostClient)->cost();
        $this->assertEqualsWithDelta(0.2016, $cost['estimated_usd'], 0.01);
    }

    public function test_sends_the_token_and_project_scoped_query(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [
                'usage' => [['measurement' => 'DISK_USAGE_GB', 'value' => 1]],
                'estimatedUsage' => [['measurement' => 'DISK_USAGE_GB', 'estimatedValue' => 1]],
            ]], 200),
        ]);

        (new RailwayCostClient)->cost();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer tok_live_example')
                && str_contains($request['query'], 'estimatedUsage')
                && $request['variables']['projectId'] === 'proj-123';
        });
    }

    public function test_returns_null_when_data_present_but_no_usable_rows(): void
    {
        // Shape changed / empty — don't fabricate a $0 cost.
        Http::fake(['*' => Http::response(['data' => ['usage' => [], 'estimatedUsage' => []]], 200)]);

        $this->assertNull((new RailwayCostClient)->cost());
    }

    public function test_degrades_to_estimate_when_usage_query_fails(): void
    {
        // estimatedUsage is primary. If the secondary `usage` (cycle-to-date)
        // query 400s, the client must STILL return a result, using the estimate
        // for both figures, rather than failing the whole Railway line.
        Http::fakeSequence()
            ->push(['data' => ['estimatedUsage' => [
                ['measurement' => 'CPU_USAGE', 'estimatedValue' => 1000],
            ]]], 200)
            ->push('Bad Request: variable type mismatch', 400);

        $cost = (new RailwayCostClient)->cost();

        $this->assertNotNull($cost);
        // estimate: 1000 vCPU-min × 0.000463 = 0.463 → 0.46; current degrades to it.
        $this->assertSame(0.46, $cost['estimated_usd']);
        $this->assertSame(0.46, $cost['current_usd']);
    }

    public function test_returns_null_when_the_estimate_query_itself_fails(): void
    {
        // If the PRIMARY estimatedUsage query fails, there's no trustworthy
        // figure at all — return null so the monitor falls back to the
        // operator-set line (never a fabricated 0).
        Http::fake(['*' => Http::response('Bad Request', 400)]);

        $this->assertNull((new RailwayCostClient)->cost());
    }
}
