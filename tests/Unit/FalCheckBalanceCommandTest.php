<?php

namespace Tests\Unit;

use App\Mail\MediaGenerationFailed;
use App\Services\Imagery\FalAiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the PROACTIVE FAL low-balance warning (fal:check-balance) and the
 * balance-fetch contract it depends on.
 *
 * The whole feature degrades safely when no admin key can read the FAL billing
 * endpoint (the inference key is 403-denied) — these tests pin that: a denied
 * or unreachable balance must NO-OP, never alert, never throw. DB-free; HTTP and
 * Mail are faked.
 */
class FalCheckBalanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        config()->set('media.alerts.low_balance_threshold', 5.0);
        config()->set('media.alerts.mailer', 'array');
        config()->set('services.fal.admin_api_key', 'fake-admin-key');
        config()->set('services.fal.api_key', 'fake-inference-key');
    }

    public function test_balance_below_threshold_dispatches_a_warning(): void
    {
        Http::fake(['api.fal.ai/*' => Http::response(['credits' => ['current_balance' => 3.10, 'currency' => 'USD']], 200)]);

        $this->artisan('fal:check-balance')->assertSuccessful();

        Mail::assertQueued(MediaGenerationFailed::class, 1);
    }

    public function test_healthy_balance_does_not_alert(): void
    {
        Http::fake(['api.fal.ai/*' => Http::response(['credits' => ['current_balance' => 42.0]], 200)]);

        $this->artisan('fal:check-balance')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_billing_403_no_admin_scope_no_ops_without_alerting(): void
    {
        // The exact production case: the key lacks billing permission.
        Http::fake(['api.fal.ai/*' => Http::response(['error' => ['type' => 'authorization_error']], 403)]);

        $this->artisan('fal:check-balance')->assertSuccessful();

        // Must NOT alert on an unreadable balance — null != $0.
        Mail::assertNothingQueued();
    }

    public function test_dry_run_below_threshold_does_not_send(): void
    {
        Http::fake(['api.fal.ai/*' => Http::response(['credits' => ['current_balance' => 1.0]], 200)]);

        $this->artisan('fal:check-balance', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_disabled_when_threshold_is_zero(): void
    {
        config()->set('media.alerts.low_balance_threshold', 0);
        // No HTTP fake needed — it should short-circuit before fetching.

        $this->artisan('fal:check-balance')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_fetch_account_balance_returns_null_on_403(): void
    {
        Http::fake(['api.fal.ai/*' => Http::response([], 403)]);
        $this->assertNull(FalAiClient::fetchAccountBalance('any-key'));
    }

    public function test_fetch_account_balance_parses_a_good_response(): void
    {
        Http::fake(['api.fal.ai/*' => Http::response(['credits' => ['current_balance' => 24.5]], 200)]);
        $this->assertSame(24.5, FalAiClient::fetchAccountBalance('admin-key'));
    }
}
