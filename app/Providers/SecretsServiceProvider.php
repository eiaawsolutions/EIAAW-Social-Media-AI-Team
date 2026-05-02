<?php

namespace App\Providers;

use App\Services\Secrets\InfisicalResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class SecretsServiceProvider extends ServiceProvider
{
    /**
     * Register the Infisical resolver and rewrite `secret://` config values
     * into real values BEFORE other providers boot.
     *
     * MUST be the FIRST provider in bootstrap/providers.php — otherwise
     * downstream providers (Mail, Stripe, queue, etc.) will read the raw
     * `secret://...` handle as if it were a real value and fail.
     */
    public function register(): void
    {
        $infisicalConfig = config('secrets.infisical', []);

        $this->app->singleton(InfisicalResolver::class, function () use ($infisicalConfig) {
            return new InfisicalResolver($infisicalConfig);
        });

        if (! ($infisicalConfig['enabled'] ?? false)) {
            return;
        }

        if (empty($infisicalConfig['client_id']) || empty($infisicalConfig['client_secret']) || empty($infisicalConfig['project_id'])) {
            Log::debug('SecretsServiceProvider: skipped — Infisical creds incomplete.');
            return;
        }

        $resolver = $this->app->make(InfisicalResolver::class);
        $paths = config('secrets.resolve', []);

        foreach ($paths as $path) {
            $current = config($path);
            if (! is_string($current) || ! str_starts_with($current, 'secret://')) {
                continue;
            }
            try {
                $resolved = $resolver->resolve($current);
                config([$path => $resolved]);
            } catch (\Throwable $e) {
                // Fail-open: a single unresolvable handle should not stop the app from booting.
                Log::error('SecretsServiceProvider: resolution failed, leaving handle in place.', [
                    'config_path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Mirror Stripe credentials into Cashier's own config block. Cashier 16
        // reads config('cashier.secret') / config('cashier.key') /
        // config('cashier.webhook.secret') at runtime (see Cashier::stripe()
        // line 123), and its vendor config snapshots env('STRIPE_SECRET') at
        // config-load time — which is the literal `secret://...` handle when
        // we use the Infisical resolver. Resolving services.stripe.* alone is
        // not enough; copy the resolved values across so Cashier sees real
        // keys instead of empty strings or unresolved handles.
        $stripeMirrors = [
            'services.stripe.key' => 'cashier.key',
            'services.stripe.secret' => 'cashier.secret',
            'services.stripe.webhook_secret' => 'cashier.webhook.secret',
        ];
        foreach ($stripeMirrors as $source => $dest) {
            $value = config($source);
            if (is_string($value) && $value !== '' && ! str_starts_with($value, 'secret://')) {
                config([$dest => $value]);
            }
        }
    }
}
