<?php

namespace Tests\Unit;

use App\Services\Imagery\FalAccountLockedException;
use App\Services\Imagery\FalAiClient;
use App\Services\Monitoring\AgentTelemetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Restore-to-service guards for the FAL.AI "User is locked / Exhausted balance"
 * outage (the Designer/Video 403 that took image generation down).
 *
 * DB-free (Http::fake + array cache, no models). Covers:
 *   - lockout classification: balance-exhaustion 403 vs a genuine permission
 *     403 vs other statuses (only the first must trip the balance breaker)
 *   - the shared breaker: trip / active / clear semantics + cool-off
 *   - generateImage()/generateVideo() throw the TYPED exception on a lockout
 *     response, trip the breaker, and short-circuit subsequent calls without
 *     a second HTTP round-trip
 *   - a good response clears the breaker (auto-resume after top-up)
 *   - the operator monitor gives the TOP-UP remedy for a balance error, NOT
 *     the wrong "re-check OAuth scope" advice the generic 403 rule gives
 */
class FalAccountLockoutTest extends TestCase
{
    private const LOCKED_BODY = '{"detail":"User is locked. Reason: Exhausted balance. Top up your balance at fal.ai/dashboard/billing."}';

    protected function setUp(): void
    {
        parent::setUp();
        FalAiClient::clearLockout();
    }

    protected function tearDown(): void
    {
        FalAiClient::clearLockout();
        parent::tearDown();
    }

    private function client(): FalAiClient
    {
        return new FalAiClient(
            apiKey: 'fal_test_key',
            imageModel: 'fal-ai/nano-banana',
        );
    }

    // ── classification ──────────────────────────────────────────────────────

    public function test_balance_exhaustion_403_is_classified_as_account_lockout(): void
    {
        $this->assertTrue(FalAiClient::isAccountLockoutBody(403, self::LOCKED_BODY));
        $this->assertTrue(FalAiClient::isAccountLockoutBody(403, 'User is locked'));
        $this->assertTrue(FalAiClient::isAccountLockoutBody(403, 'please TOP UP YOUR BALANCE'));
    }

    public function test_genuine_permission_403_is_not_an_account_lockout(): void
    {
        // A real scope/key 403 must NOT trip the balance breaker — its remedy is
        // a key rotation, not a top-up.
        $this->assertFalse(FalAiClient::isAccountLockoutBody(403, '{"detail":"Forbidden: invalid API key"}'));
        $this->assertFalse(FalAiClient::isAccountLockoutBody(403, 'insufficient scope for this model'));
    }

    public function test_non_403_statuses_are_never_lockouts(): void
    {
        $this->assertFalse(FalAiClient::isAccountLockoutBody(429, self::LOCKED_BODY));
        $this->assertFalse(FalAiClient::isAccountLockoutBody(500, 'exhausted balance'));
        $this->assertFalse(FalAiClient::isAccountLockoutBody(200, self::LOCKED_BODY));
    }

    // ── breaker ─────────────────────────────────────────────────────────────

    public function test_breaker_trip_active_and_clear(): void
    {
        $this->assertFalse(FalAiClient::lockoutActive());

        FalAiClient::tripLockout();
        $this->assertTrue(FalAiClient::lockoutActive());

        FalAiClient::clearLockout();
        $this->assertFalse(FalAiClient::lockoutActive());
    }

    public function test_breaker_respects_cooloff_ttl(): void
    {
        FalAiClient::tripLockout();
        $this->assertTrue(FalAiClient::lockoutActive());

        // After the cool-off elapses the breaker re-opens so FAL is probed again
        // (a top-up resumes service without a deploy).
        $this->travel(3)->minutes();
        $this->assertFalse(FalAiClient::lockoutActive());
    }

    // ── image generation ────────────────────────────────────────────────────

    public function test_generate_image_throws_typed_exception_and_trips_breaker_on_lockout(): void
    {
        Http::fake(['fal.run/*' => Http::response(self::LOCKED_BODY, 403)]);

        try {
            $this->client()->generateImage('a brand photo');
            $this->fail('Expected FalAccountLockedException.');
        } catch (FalAccountLockedException $e) {
            $this->assertStringContainsString('balance', strtolower($e->getMessage()));
            $this->assertStringContainsString('fal.ai/dashboard/billing', $e->getMessage());
        }

        $this->assertTrue(FalAiClient::lockoutActive(), 'A lockout response must open the breaker.');
    }

    public function test_open_breaker_short_circuits_without_a_second_http_call(): void
    {
        Http::fake(['fal.run/*' => Http::response(self::LOCKED_BODY, 403)]);

        // First call hits FAL and trips the breaker.
        try {
            $this->client()->generateImage('first');
        } catch (FalAccountLockedException) {
        }
        Http::assertSentCount(1);

        // Second call must be served by the breaker — no new HTTP round-trip.
        $this->expectException(FalAccountLockedException::class);
        try {
            $this->client()->generateImage('second');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_generic_403_throws_runtime_exception_and_does_not_trip_breaker(): void
    {
        Http::fake(['fal.run/*' => Http::response('{"detail":"Forbidden: invalid API key"}', 403)]);

        try {
            $this->client()->generateImage('a brand photo');
            $this->fail('Expected a RuntimeException.');
        } catch (FalAccountLockedException $e) {
            $this->fail('A permission 403 must NOT be treated as an account lockout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
        }

        $this->assertFalse(FalAiClient::lockoutActive(), 'A permission 403 must not open the balance breaker.');
    }

    public function test_successful_image_clears_the_breaker(): void
    {
        // Simulate a stale breaker (e.g. set just before a top-up landed).
        FalAiClient::tripLockout();
        $this->assertTrue(FalAiClient::lockoutActive());

        Http::fake(['fal.run/*' => Http::response([
            'images' => [['url' => 'https://fal.media/files/img.jpg', 'content_type' => 'image/jpeg']],
        ], 200)]);

        // The breaker is checked first, so a successful probe only happens once
        // the cool-off has expired. Verify the success path clears it.
        $this->travel(3)->minutes();
        $result = $this->client()->generateImage('a brand photo');

        $this->assertSame('https://fal.media/files/img.jpg', $result['url']);
        $this->assertFalse(FalAiClient::lockoutActive(), 'A good response must close the breaker.');
    }

    // ── video generation shares the same locked account ─────────────────────

    public function test_generate_video_also_trips_and_respects_the_shared_breaker(): void
    {
        $client = new FalAiClient(
            apiKey: 'fal_test_key',
            imageModel: 'fal-ai/nano-banana',
            videoModelText: 'fal-ai/veo3/fast',
            videoModelImage: 'fal-ai/veo3/fast/image-to-video',
        );

        Http::fake(['fal.run/*' => Http::response(self::LOCKED_BODY, 403)]);

        $this->expectException(FalAccountLockedException::class);
        try {
            $client->generateVideo('a brand reel');
        } finally {
            $this->assertTrue(FalAiClient::lockoutActive());
        }
    }

    // ── operator guidance ───────────────────────────────────────────────────

    public function test_telemetry_gives_topup_remedy_for_balance_error_not_oauth(): void
    {
        $action = $this->nextAction(
            'failing',
            'Image generation failed: FAL.AI account locked: prepaid balance exhausted. Top up at fal.ai/dashboard/billing.',
            'designer',
        );

        $this->assertStringContainsStringIgnoringCase('top up', $action);
        $this->assertStringContainsString('fal.ai/dashboard/billing', $action);
        // The pre-fix bug: a 403 balance error was told to "re-check OAuth scope".
        $this->assertStringNotContainsStringIgnoringCase('oauth', $action);
        $this->assertStringNotContainsStringIgnoringCase('api key permissions', $action);
    }

    public function test_telemetry_still_gives_oauth_remedy_for_a_plain_permission_403(): void
    {
        // Regression guard: the balance patterns must not swallow a genuine
        // permission 403 (no balance/lockout phrasing) — that one still routes
        // to the key/scope remedy.
        $action = $this->nextAction('failing', 'Blotato createPost failed: HTTP 403 forbidden', 'designer');

        $this->assertStringContainsStringIgnoringCase('permission', $action);
    }

    private function nextAction(string $status, string $error, string $role): string
    {
        $m = new ReflectionMethod(AgentTelemetry::class, 'nextActionFor');
        $m->setAccessible(true);

        return (string) $m->invoke(new AgentTelemetry(), $status, $error, $role);
    }
}
