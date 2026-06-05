<?php

namespace App\Services\Secrets;

use Illuminate\Support\Facades\Log;

/**
 * Self-heals `secret://` config handles that were left UNRESOLVED at boot.
 *
 * THE PROBLEM
 * -----------
 * SecretsServiceProvider resolves every config('secrets.resolve') path from
 * Infisical ONCE, at boot, fail-open: if Infisical flaps during that single
 * attempt the literal `secret://…` handle stays in config for the WHOLE lifetime
 * of that process. On a long-lived Railway QUEUE WORKER that means every job
 * needing the flapped secret fails forever — observed as:
 *   - Resend mail  → Resend\Laravel\Exceptions\ApiKeyIsMissing
 *   - Metricool    → "PUBLISH_PROVIDER=metricool but Metricool is not configured"
 * until the worker is redeployed. The web process (resolved fine at its own boot)
 * works, so the bug looks intermittent and per-process. See
 * [[metricool_metrics_and_poll_bridge]] (MetricoolClient already self-heals its
 * one token; this generalises the same idea to ALL secret paths).
 *
 * THE FIX
 * -------
 * Re-resolve, on demand, any allow-listed path whose config value is STILL a
 * `secret://` handle (or empty), caching the resolved value back into config for
 * the rest of the process. Call ensureResolved() at the start of every queued
 * job (wired in AppServiceProvider via Queue::before) so a poisoned worker heals
 * itself before running the job, with no redeploy.
 *
 * Fail-open + cheap: when nothing is unresolved (the normal case) it does a few
 * string checks and returns. It never throws into the job hot path.
 */
class SecretsHealer
{
    /**
     * Re-resolve any still-unresolved secret:// handles among the given config
     * paths (defaults to the full secrets.resolve allow-list). Returns the number
     * of paths healed this call (0 = nothing to do, the common case).
     *
     * @param  list<string>|null  $paths  subset to check; null = all allow-listed
     */
    public static function ensureResolved(?array $paths = null): int
    {
        if (! (bool) config('secrets.infisical.enabled', false)) {
            return 0; // resolver intentionally off (e.g. local dev)
        }

        $paths ??= (array) config('secrets.resolve', []);
        $healed = 0;

        foreach ($paths as $path) {
            $current = config($path);

            // Only act on a leftover handle. A resolved value, a plain env value,
            // or a genuinely-absent secret (null) we leave alone — re-resolving a
            // null would be pointless and an empty string isn't necessarily a
            // boot flap (could be legitimately unset in this env).
            if (! is_string($current) || ! str_starts_with($current, 'secret://')) {
                continue;
            }

            try {
                $resolved = app(InfisicalResolver::class)->resolve($current);
                if (is_string($resolved) && $resolved !== '' && ! str_starts_with($resolved, 'secret://')) {
                    config([$path => $resolved]);
                    $healed++;
                }
            } catch (\Throwable $e) {
                // Infisical still down — leave the handle, let the dependent
                // feature's own guard take over. Don't throw into the worker.
                Log::warning('SecretsHealer: re-resolve failed; leaving handle in place.', [
                    'config_path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($healed > 0) {
            Log::info("SecretsHealer: re-resolved {$healed} secret handle(s) left unresolved at boot (Infisical flap self-heal).");

            // Stripe→Cashier mirror must follow any Stripe re-resolution, same as
            // SecretsServiceProvider does at boot, so Cashier sees real keys.
            self::mirrorStripeToCashier();

            // Rebuild any CACHED clients that captured the stale key at first
            // resolution. Healing config alone is NOT enough for packages that
            // bind their client as a SINGLETON reading config once — notably
            // resend-laravel binds Resend\Contracts\Client as a singleton
            // (config('resend.api_key') captured at first resolve) and the mail
            // transport injects THAT instance. A worker that built the singleton
            // before the heal keeps the missing key for life → ApiKeyIsMissing on
            // every queued Resend send. Forgetting the bindings forces the next
            // resolution to rebuild with the now-healed config. Also reset the
            // mail+notification managers so the transport re-resolves the client.
            self::forgetCachedClients();
        }

        return $healed;
    }

    /**
     * Drop container singletons / resolved managers that may have captured a
     * stale secret at first resolution, so they rebuild from healed config.
     * Best-effort: forgetting an unbound id is a harmless no-op.
     */
    private static function forgetCachedClients(): void
    {
        $app = app();

        // resend-laravel client singleton + its aliases.
        foreach ([
            \Resend\Contracts\Client::class,
            \Resend\Client::class,
            'resend',
        ] as $id) {
            try {
                if ($app->bound($id) || $app->resolved($id)) {
                    $app->forgetInstance($id);
                }
            } catch (\Throwable) {
                // ignore — class may not exist in some envs
            }
        }

        // The mail manager caches built mailers (incl. the resend transport that
        // injected the old client). Drop it so the next Mail::mailer() rebuilds
        // the transport against the rebuilt client. 'mail.manager' is Laravel's
        // bound id; forgetting it is safe (lazily recreated on next use).
        foreach (['mail.manager', 'mailer'] as $id) {
            try {
                $app->forgetInstance($id);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /** Mirror resolved services.stripe.* into Cashier's own config block. */
    private static function mirrorStripeToCashier(): void
    {
        $mirrors = [
            'services.stripe.key' => 'cashier.key',
            'services.stripe.secret' => 'cashier.secret',
            'services.stripe.webhook_secret' => 'cashier.webhook.secret',
        ];
        foreach ($mirrors as $source => $dest) {
            $value = config($source);
            if (is_string($value) && $value !== '' && ! str_starts_with($value, 'secret://')) {
                config([$dest => $value]);
            }
        }
    }
}
