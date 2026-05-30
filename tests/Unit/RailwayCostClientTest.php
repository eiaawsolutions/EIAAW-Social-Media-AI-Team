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
 * cost), and the resource-quantity → USD pricing math is faithful to the
 * configured unit prices.
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
        config()->set('costs.railway.unit_prices_usd', [
            'cpu_vcpu_minute' => 0.000463,
            'memory_gb_minute' => 0.000231,
            'disk_gb_month' => 0.15,
            'network_tx_gb' => 0.05,
            'backup_gb_month' => 0.15,
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

    public function test_prices_usage_rows_into_usd(): void
    {
        // usage: 1000 vCPU-min, 2000 GB-min mem, 10 GB disk, 5 GB egress.
        //   cpu  = 1000 × 0.000463  = 0.463
        //   mem  = 2000 × 0.000231  = 0.462
        //   disk =   10 × 0.15      = 1.50
        //   net  =    5 × 0.05      = 0.25
        //   total = 2.675 → round 2.68
        // estimatedUsage doubles cpu/mem to project a higher end-of-cycle.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'usage' => [
                        ['measurement' => 'CPU_USAGE', 'value' => 1000],
                        ['measurement' => 'MEMORY_USAGE_GB', 'value' => 2000],
                        ['measurement' => 'DISK_USAGE_GB', 'value' => 10],
                        ['measurement' => 'NETWORK_TX_GB', 'value' => 5],
                    ],
                    'estimatedUsage' => [
                        ['measurement' => 'CPU_USAGE', 'estimatedValue' => 2000],
                        ['measurement' => 'MEMORY_USAGE_GB', 'estimatedValue' => 4000],
                        ['measurement' => 'DISK_USAGE_GB', 'estimatedValue' => 10],
                        ['measurement' => 'NETWORK_TX_GB', 'estimatedValue' => 5],
                    ],
                ],
            ], 200),
        ]);

        $cost = (new RailwayCostClient)->cost();

        $this->assertNotNull($cost);
        $this->assertSame('railway-api', $cost['source']);
        $this->assertSame(2.68, $cost['current_usd']);
        // estimated: cpu 0.926 + mem 0.924 + disk 1.50 + net 0.25 = 3.60
        $this->assertSame(3.60, $cost['estimated_usd']);
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
}
