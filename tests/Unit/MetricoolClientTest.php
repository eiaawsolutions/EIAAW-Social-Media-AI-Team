<?php

namespace Tests\Unit;

use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * MetricoolClient — evaluation client for the Blotato→Metricool switch.
 *
 * DB-free (Http::fake, no models). We assert the request CONTRACT that the
 * multi-tenancy decision depends on:
 *   - auth is the X-Mc-Auth header (NOT Authorization: Bearer)
 *   - EVERY call carries userId, and brand-scoped calls carry the right blogId
 *     (this is the server-side isolation invariant that replaces Blotato's
 *     one-account-per-workspace physical isolation — see memory
 *     metricool-multitenancy)
 *   - the scheduler body matches Metricool's verified shape (providers[] as
 *     objects, publicationDate{dateTime,timezone}, autoPublish, media[])
 *
 * We assert OUR request shape, not Metricool's behaviour.
 */
class MetricoolClientTest extends TestCase
{
    private function client(): MetricoolClient
    {
        return new MetricoolClient(
            apiToken: 'mc_test_token',
            userId: 4242,
            baseUrl: 'https://app.metricool.com/api',
            timeout: 30,
        );
    }

    public function test_constructor_rejects_empty_token(): void
    {
        $this->expectException(RuntimeException::class);
        new MetricoolClient(apiToken: '', userId: 1, baseUrl: 'https://app.metricool.com/api');
    }

    public function test_constructor_rejects_non_positive_user_id(): void
    {
        $this->expectException(RuntimeException::class);
        new MetricoolClient(apiToken: 'mc_x', userId: 0, baseUrl: 'https://app.metricool.com/api');
    }

    public function test_from_config_returns_null_when_token_is_unresolved_handle(): void
    {
        // An unresolved secret:// handle (Infisical disabled locally) must read
        // as "not configured" so probes no-op cleanly rather than sending a
        // literal handle string as a token.
        config()->set('services.metricool.api_token', 'secret://eiaaw-smt-prod/prod/METRICOOL_API_TOKEN');
        config()->set('services.metricool.user_id', 4242);

        $this->assertNull(MetricoolClient::fromConfig());
    }

    public function test_from_config_returns_null_when_user_id_missing(): void
    {
        config()->set('services.metricool.api_token', 'mc_real_token');
        config()->set('services.metricool.user_id', 0);

        $this->assertNull(MetricoolClient::fromConfig());
    }

    public function test_list_brands_sends_user_id_and_auth_header(): void
    {
        Http::fake([
            'app.metricool.com/api/admin/simpleProfiles*' => Http::response([
                ['id' => 111, 'label' => 'Brand A'],
                ['id' => 222, 'label' => 'Brand B'],
            ], 200),
        ]);

        $brands = $this->client()->listBrands();

        $this->assertCount(2, $brands);
        $this->assertSame(111, $brands[0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/admin/simpleProfiles')
                && $request->hasHeader('X-Mc-Auth', 'mc_test_token')
                && ! $request->hasHeader('Authorization')        // NOT bearer
                && str_contains($request->url(), 'userId=4242');
        });
    }

    public function test_post_analytics_scopes_to_blog_id_and_window(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/analytics/posts/instagram*' => Http::response([
                'data' => [['id' => 'p1', 'likes' => 10, 'impressions' => 500]],
            ], 200),
        ]);

        $result = $this->client()->postAnalytics(
            blogId: 222,
            from: '2026-05-01',
            to: '2026-05-30',
            network: 'instagram',
        );

        $this->assertTrue($result['found']);

        // THE isolation invariant: the brand-scoped call MUST carry blogId=222
        // (the wrong blogId here would be a cross-tenant leak).
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/analytics/posts/instagram')
                && str_contains($request->url(), 'userId=4242')
                && str_contains($request->url(), 'blogId=222')
                && str_contains($request->url(), 'from=2026-05-01')
                && str_contains($request->url(), 'to=2026-05-30');
        });
    }

    public function test_post_analytics_treats_404_as_not_found_not_error(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/analytics/posts/threads*' => Http::response(
                ['message' => 'Not available on plan'], 404
            ),
        ]);

        $result = $this->client()->postAnalytics(123, '2026-05-01', '2026-05-30', 'threads');

        $this->assertFalse($result['found']);
        $this->assertSame(404, $result['status']);
    }

    public function test_schedule_post_dry_run_builds_body_without_sending(): void
    {
        Http::fake(); // any real send would fail the assertNothingSent below

        $built = $this->client()->schedulePost(
            blogId: 222,
            networks: ['linkedin', 'instagram'],
            text: 'hello world',
            publicationDateTime: '2026-06-01T10:00:00',
            timezone: 'Asia/Kuala_Lumpur',
            media: ['mediaId-1'],
            autoPublish: false,
            perNetworkData: ['tiktokData' => ['privacyLevel' => 'SELF_ONLY']],
            dryRun: true,
        );

        $this->assertTrue($built['dry_run']);
        Http::assertNothingSent();

        $body = $built['body'];
        // providers must be OBJECTS, not strings (verified Metricool contract).
        $this->assertSame([['network' => 'linkedin'], ['network' => 'instagram']], $body['providers']);
        $this->assertSame('hello world', $body['text']);
        $this->assertSame('2026-06-01T10:00:00', $body['publicationDate']['dateTime']);
        $this->assertSame('Asia/Kuala_Lumpur', $body['publicationDate']['timezone']);
        $this->assertFalse($body['autoPublish']);
        $this->assertSame(['mediaId-1'], $body['media']);
        $this->assertSame(['privacyLevel' => 'SELF_ONLY'], $body['tiktokData']);
    }

    public function test_schedule_post_live_sends_blog_scoped_body(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-1'], 200),
        ]);

        $res = $this->client()->schedulePost(
            blogId: 222,
            networks: ['linkedin'],
            text: 'live post',
            publicationDateTime: '2026-06-01T10:00:00',
            timezone: 'Asia/Kuala_Lumpur',
            autoPublish: true,
        );

        $this->assertFalse($res['dry_run']);
        $this->assertSame(200, $res['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/scheduler/posts')
                && $request->method() === 'POST'
                && $request->hasHeader('X-Mc-Auth', 'mc_test_token')
                && str_contains($request->url(), 'userId=4242')
                && str_contains($request->url(), 'blogId=222')
                && $request['text'] === 'live post'
                && $request['autoPublish'] === true
                && $request['providers'][0]['network'] === 'linkedin';
        });
    }

    public function test_throws_on_server_error(): void
    {
        Http::fake([
            'app.metricool.com/api/admin/simpleProfiles*' => Http::response('upstream down', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Metricool listBrands failed: HTTP 500/');
        $this->client()->listBrands();
    }

    // ── Account timeline (GET /stats/timeline/{metric}) — the growth dashboard ──

    public function test_account_timeline_scopes_to_blog_id_and_uses_start_end_ymd(): void
    {
        Http::fake([
            'app.metricool.com/api/stats/timeline/igFollowers*' => Http::response([
                ['20260501', '100'],
                ['20260502', '105'],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(222, 'igFollowers', '20260501', '20260530');

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['points']);
        // Compact YMD timestamp normalised to ISO date; value typed numeric.
        $this->assertSame('2026-05-01', $result['points'][0]['date']);
        $this->assertSame(100, $result['points'][0]['value']);

        // Isolation + the start/end (NOT from/to) param convention this endpoint wants.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/stats/timeline/igFollowers')
                && $request->hasHeader('X-Mc-Auth', 'mc_test_token')
                && str_contains($request->url(), 'userId=4242')
                && str_contains($request->url(), 'blogId=222')
                && str_contains($request->url(), 'start=20260501')
                && str_contains($request->url(), 'end=20260530');
        });
    }

    public function test_account_timeline_treats_404_as_not_found(): void
    {
        Http::fake([
            'app.metricool.com/api/stats/timeline/*' => Http::response(['message' => 'no metric'], 404),
        ]);

        $result = $this->client()->getAccountTimeline(123, 'inFollowers', '20260501', '20260530');

        $this->assertFalse($result['found']);
        $this->assertSame(404, $result['status']);
        $this->assertSame([], $result['points']);
    }

    public function test_account_timeline_accepts_object_envelope_and_named_keys(): void
    {
        // Version drift: enveloped {values:[{date,value}, …]} instead of pairs.
        Http::fake([
            'app.metricool.com/api/stats/timeline/pageImpressions*' => Http::response([
                'values' => [
                    ['date' => '2026-05-01', 'value' => 1200],
                    ['date' => '2026-05-02', 'value' => 1350],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(222, 'pageImpressions', '20260501', '20260530');

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['points']);
        $this->assertSame('2026-05-02', $result['points'][1]['date']);
        $this->assertSame(1350, $result['points'][1]['value']);
    }

    public function test_account_timeline_drops_non_numeric_values_never_zeroes_them(): void
    {
        // Truthfulness Contract: a null/blank reading is omitted, not coerced to 0.
        Http::fake([
            'app.metricool.com/api/stats/timeline/twitterFollowers*' => Http::response([
                ['20260501', '500'],
                ['20260502', null],
                ['20260503', ''],
                ['20260504', '512'],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(222, 'twitterFollowers', '20260501', '20260530');

        $this->assertCount(2, $result['points']); // the null + blank rows dropped
        $this->assertSame(500, $result['points'][0]['value']);
        $this->assertSame(512, $result['points'][1]['value']);
    }
}
