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

    public function test_recovers_handle_from_env_when_config_is_empty(): void
    {
        // The real worker case: boot-time config:cache baked an EMPTY value after
        // an Infisical flap, so config() returns '' (NOT a secret:// handle). The
        // healer must recover the original handle from the OS env var (getenv) and
        // resolve THAT — otherwise an empty-config worker can never self-heal.
        config(['secrets.infisical.enabled' => true]);
        config(['resend.api_key' => '']); // cached-empty, the poison state

        // RESEND_KEY is the source env var for resend.api_key (PATH_ENV_SOURCE).
        putenv('RESEND_KEY=secret://eiaaw-all-projects/prod/RESEND_API');

        $this->bindResolver(function (string $h): string {
            // Must be invoked with the ENV-recovered handle, not the empty config.
            \PHPUnit\Framework\Assert::assertSame('secret://eiaaw-all-projects/prod/RESEND_API', $h);
            return 're_RECOVERED_FROM_ENV';
        });

        $healed = SecretsHealer::ensureResolved(['resend.api_key']);

        $this->assertSame(1, $healed);
        $this->assertSame('re_RECOVERED_FROM_ENV', config('resend.api_key'));

        putenv('RESEND_KEY'); // unset to avoid leaking into other tests
    }

    public function test_empty_config_with_no_env_source_is_left_alone(): void
    {
        // A path with no PATH_ENV_SOURCE mapping and empty config = genuinely
        // unset; the healer must NOT invent a handle or call the resolver.
        config(['secrets.infisical.enabled' => true]);
        config(['some.unmapped.secret' => '']);

        $this->bindResolver(function (): string {
            $this->fail('resolver must not run for an unmapped empty path');
        });

        $this->assertSame(0, SecretsHealer::ensureResolved(['some.unmapped.secret']));
    }

    public function test_healing_forgets_the_cached_resend_singleton(): void
    {
        // The real prod failure: resend-laravel binds Resend\Contracts\Client as a
        // SINGLETON capturing the key at first resolve. A worker that built it
        // against a poisoned config keeps ApiKeyIsMissing for life. After healing,
        // SecretsHealer must FORGET that singleton so the next resolve rebuilds it.
        config(['secrets.infisical.enabled' => true]);
        config(['resend.api_key' => 'secret://x/y/RESEND']);

        // Stand a sentinel instance in the container under the resend client id,
        // standing in for the singleton a poisoned worker would have built.
        $sentinel = new \stdClass();
        $this->app->instance(\Resend\Contracts\Client::class, $sentinel);
        $this->assertTrue($this->app->resolved(\Resend\Contracts\Client::class));

        $this->bindResolver(fn (string $h) => 're_REAL_resolved_key');

        SecretsHealer::ensureResolved(['resend.api_key']);

        // After healing, the stale instance must have been forgotten — the
        // container no longer reports it as resolved, so the next make() rebuilds
        // against the healed config instead of returning the poisoned sentinel.
        $this->assertFalse(
            $this->app->resolved(\Resend\Contracts\Client::class),
            'stale resend client singleton should be forgotten after heal',
        );
    }
}
