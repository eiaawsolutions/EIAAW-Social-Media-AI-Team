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

    /**
     * Self-heal guard: a leftover secret:// handle with the resolver DISABLED
     * must NOT attempt resolution (no Infisical call in local/test) and still
     * read as "not configured" — protects the existing no-op-when-unprovisioned
     * behaviour after the 2026-06-02 flap fix.
     */
    public function test_from_config_does_not_self_heal_when_resolver_disabled(): void
    {
        config()->set('secrets.infisical.enabled', false);
        config()->set('services.metricool.api_token', 'secret://eiaaw-all-projects/prod/METRICOOL_API_TOKEN');
        config()->set('services.metricool.user_id', 4242);

        $this->assertNull(MetricoolClient::fromConfig());
    }

    public function test_get_scheduled_posts_sends_blog_id_and_window_and_parses_rows(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response([
                'data' => [
                    ['id' => '332536114', 'providers' => [['network' => 'facebook', 'status' => 'PUBLISHED']]],
                    ['id' => '332536115', 'providers' => [['network' => 'instagram', 'status' => 'PENDING']]],
                ],
            ], 200),
        ]);

        $res = $this->client()->getScheduledPosts(6322515, '2026-06-01T00:00:00', '2026-06-03T23:59:59');

        $this->assertTrue($res['found']);
        $this->assertCount(2, $res['rows']);
        $this->assertSame('332536114', $res['rows'][0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/scheduler/posts')
                && $request->hasHeader('X-Mc-Auth', 'mc_test_token')
                && str_contains($request->url(), 'blogId=6322515')
                // scheduler endpoint uses start/end (NOT from/to)
                && str_contains($request->url(), 'start=')
                && str_contains($request->url(), 'end=');
        });
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

    // ── Account timeline (GET /v2/analytics/timelines) — the growth dashboard ──
    // Endpoint + response shape VERIFIED LIVE 2026-05-31 (prod blogId 6322515):
    // {"data":[{"metric":"Followers","values":[{"dateTime":"2026-05-29T12:00:00+0200","value":7.0}]}]}.

    public function test_account_timeline_sends_metric_network_subject_and_iso_window(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response([
                'data' => [[
                    'metric' => 'Followers',
                    'values' => [
                        ['dateTime' => '2026-05-29T12:00:00+0200', 'value' => 7.0],
                        ['dateTime' => '2026-05-30T12:00:00+0200', 'value' => 12.0],
                    ],
                ]],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(
            blogId: 222,
            metric: 'Followers',
            network: 'instagram',
            fromIso: '2026-05-01T00:00:00',
            toIso: '2026-05-30T23:59:59',
        );

        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['points']);
        $this->assertSame('2026-05-29', $result['points'][0]['date']); // ISO → date
        $this->assertSame(7, $result['points'][0]['value']);

        // The real contract: metric + network + subject=account + ISO from/to,
        // scoped to the right blogId (the cross-tenant isolation invariant).
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/analytics/timelines')
                && $request->hasHeader('X-Mc-Auth', 'mc_test_token')
                && str_contains($request->url(), 'userId=4242')
                && str_contains($request->url(), 'blogId=222')
                && str_contains($request->url(), 'metric=Followers')
                && str_contains($request->url(), 'network=instagram')
                && str_contains($request->url(), 'subject=account')
                && str_contains(rawurldecode($request->url()), 'from=2026-05-01T00:00:00')
                && str_contains(rawurldecode($request->url()), 'to=2026-05-30T23:59:59');
        });
    }

    /**
     * Invalid metric for a network (400), not-connected (403 "There is no
     * <network> connection for blog" / 404), or upstream 500 must degrade that one
     * network to not-found — never blow up the board. 403 added 2026-06-10 after
     * X/Twitter returned it live for an unconnected network on the HQ brand.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonFatalStatusProvider')]
    public function test_account_timeline_treats_non_2xx_as_not_found_not_error(int $status): void
    {
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response(['status' => 'x'], $status),
        ]);

        $result = $this->client()->getAccountTimeline(123, 'Followers', 'linkedin', '2026-05-01T00:00:00', '2026-05-30T23:59:59');

        $this->assertFalse($result['found'], "status {$status} should be found=false");
        $this->assertSame($status, $result['status']);
        $this->assertSame([], $result['points']);
    }

    /** @return array<string, array{int}> */
    public static function nonFatalStatusProvider(): array
    {
        return [
            'invalid metric (400)' => [400],
            'not connected (403)' => [403],
            'not connected (404)' => [404],
            'upstream error (500)' => [500],
        ];
    }

    public function test_post_analytics_treats_403_no_connection_as_not_found(): void
    {
        // Metricool returns 403 "There is no twitter connection for blog: N" for a
        // network the brand hasn't connected — same meaning as 404, must NOT throw.
        Http::fake([
            'app.metricool.com/api/v2/analytics/posts/twitter*' => Http::response(
                ['status' => 'FORBIDDEN', 'code' => '403', 'detail' => 'There is no twitter connection for blog: 123'], 403
            ),
        ]);

        $result = $this->client()->postAnalytics(123, '2026-05-01', '2026-05-30', 'twitter');

        $this->assertFalse($result['found']);
        $this->assertSame(403, $result['status']);
    }

    public function test_account_timeline_picks_the_matching_metric_series(): void
    {
        // Be defensive: if the body carries multiple series, pick the one whose
        // metric matches the request (not just the first).
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response([
                'data' => [
                    ['metric' => 'Other', 'values' => [['dateTime' => '2026-05-01T12:00:00+0000', 'value' => 999]]],
                    ['metric' => 'impressions', 'values' => [['dateTime' => '2026-05-02T12:00:00+0000', 'value' => 1350]]],
                ],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(222, 'impressions', 'instagram', '2026-05-01T00:00:00', '2026-05-30T23:59:59');

        $this->assertCount(1, $result['points']);
        $this->assertSame('2026-05-02', $result['points'][0]['date']);
        $this->assertSame(1350, $result['points'][0]['value']);
    }

    public function test_account_timeline_drops_non_numeric_values_never_zeroes_them(): void
    {
        // Truthfulness Contract: a null/blank reading is omitted, not coerced to 0.
        Http::fake([
            'app.metricool.com/api/v2/analytics/timelines*' => Http::response([
                'data' => [[
                    'metric' => 'Followers',
                    'values' => [
                        ['dateTime' => '2026-05-01T12:00:00+0000', 'value' => 500],
                        ['dateTime' => '2026-05-02T12:00:00+0000', 'value' => null],
                        ['dateTime' => '2026-05-03T12:00:00+0000', 'value' => '512'],
                    ],
                ]],
            ], 200),
        ]);

        $result = $this->client()->getAccountTimeline(222, 'Followers', 'twitter', '2026-05-01T00:00:00', '2026-05-30T23:59:59');

        $this->assertCount(2, $result['points']); // the null row dropped
        $this->assertSame(500, $result['points'][0]['value']);
        $this->assertSame(512, $result['points'][1]['value']);
    }
}
