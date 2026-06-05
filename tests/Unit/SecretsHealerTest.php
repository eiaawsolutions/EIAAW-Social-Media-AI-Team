<?php

namespace Tests\Unit;

use App\Services\Secrets\InfisicalResolver;
use App\Services\Secrets\SecretsHealer;
use Tests\TestCase;

/**
 * SecretsHealer re-resolves `secret://` config handles that were left UNRESOLVED
 * at boot (Infisical flap), so a poisoned long-lived worker self-heals before
 * each queued job instead of failing every secret-dependent job for its whole
 * life (Resend ApiKeyIsMissing, Metricool "not configured", …).
 *
 * DB-free. We bind a fake InfisicalResolver so no network is touched.
 */
class SecretsHealerTest extends TestCase
{
    private function bindResolver(callable $resolve): void
    {
        $this->app->instance(InfisicalResolver::class, new class ($resolve) extends InfisicalResolver {
            /** @var callable */
            private $resolveFn;

            public function __construct(callable $resolve)
            {
                parent::__construct([]); // config unused by the override
                $this->resolveFn = $resolve;
            }

            public function resolve(string $handle): string
            {
                return ($this->resolveFn)($handle);
            }
        });
    }

    public function test_heals_a_leftover_handle_into_config(): void
    {
        config(['secrets.infisical.enabled' => true]);
        config(['services.resend.key' => 'secret://eiaaw-smt-prod/prod/RESEND_KEY']);
        config(['resend.api_key' => 'secret://eiaaw-smt-prod/prod/RESEND_KEY']);

        $this->bindResolver(fn (string $h) => 're_REAL_KEY_value_resolved');

        $healed = SecretsHealer::ensureResolved(['services.resend.key', 'resend.api_key']);

        $this->assertSame(2, $healed);
        $this->assertSame('re_REAL_KEY_value_resolved', config('services.resend.key'));
        $this->assertSame('re_REAL_KEY_value_resolved', config('resend.api_key'));
    }

    public function test_noop_when_already_resolved(): void
    {
        config(['secrets.infisical.enabled' => true]);
        config(['services.resend.key' => 're_already_real']);

        // Resolver must never be called when there's no leftover handle.
        $this->bindResolver(function (): string {
            $this->fail('resolver should not be invoked when config is already resolved');
        });

        $healed = SecretsHealer::ensureResolved(['services.resend.key']);

        $this->assertSame(0, $healed);
        $this->assertSame('re_already_real', config('services.resend.key'));
    }

    public function test_disabled_resolver_is_a_noop(): void
    {
        config(['secrets.infisical.enabled' => false]);
        config(['services.resend.key' => 'secret://x/y/Z']);

        $this->bindResolver(function (): string {
            $this->fail('resolver should not run when Infisical is disabled');
        });

        $this->assertSame(0, SecretsHealer::ensureResolved(['services.resend.key']));
        // Handle left untouched (local-dev / disabled mode).
        $this->assertStringStartsWith('secret://', config('services.resend.key'));
    }

    public function test_fail_open_leaves_handle_when_resolver_throws(): void
    {
        config(['secrets.infisical.enabled' => true]);
        config(['services.resend.key' => 'secret://x/y/Z']);

        $this->bindResolver(fn (string $h) => throw new \RuntimeException('infisical down'));

        // Must NOT throw into the job hot path; returns 0 and leaves the handle.
        $healed = SecretsHealer::ensureResolved(['services.resend.key']);

        $this->assertSame(0, $healed);
        $this->assertStringStartsWith('secret://', config('services.resend.key'));
    }

    public function test_still_unresolved_result_is_not_cached(): void
    {
        config(['secrets.infisical.enabled' => true]);
        config(['services.resend.key' => 'secret://x/y/Z']);

        // Resolver returns ANOTHER handle (a misconfiguration) — must be rejected,
        // not written back as if real.
        $this->bindResolver(fn (string $h) => 'secret://still/a/HANDLE');

        $this->assertSame(0, SecretsHealer::ensureResolved(['services.resend.key']));
        $this->assertSame('secret://x/y/Z', config('services.resend.key'));
    }
}
