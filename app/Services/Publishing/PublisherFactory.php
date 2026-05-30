<?php

namespace App\Services\Publishing;

use App\Services\Metricool\MetricoolClient;
use RuntimeException;

/**
 * Selects the publishing provider from the PUBLISH_PROVIDER config flag
 * (services.publishing.provider). This is the seam that makes the
 * Blotato→Metricool publishing switch a config flip, not a redeploy.
 *
 * Default: 'metricool' (the switch). 'blotato' is the rollback path until
 * Blotato is decommissioned in a follow-up PR.
 *
 * Metricool resolution degrades safely: if PUBLISH_PROVIDER=metricool but the
 * integration isn't configured (no token), we DON'T silently fall back to
 * Blotato — we throw, because a misconfigured publish provider should surface
 * loudly, not quietly post via the wrong backend (which on Blotato would also
 * require per-workspace keys that may not exist post-migration).
 */
class PublisherFactory
{
    public function make(): Publisher
    {
        // Treat null/empty as the default (the switch). config() returns null
        // for a key that exists with a null value, so the 2nd-arg default
        // doesn't cover an explicit null — normalise here.
        $provider = strtolower((string) config('services.publishing.provider', 'metricool'));
        if ($provider === '') {
            $provider = 'metricool';
        }

        return match ($provider) {
            'blotato' => new BlotatoPublisher(),
            'metricool' => $this->makeMetricool(),
            default => throw new RuntimeException(
                "Unknown PUBLISH_PROVIDER '{$provider}'. Use 'metricool' or 'blotato'."
            ),
        };
    }

    private function makeMetricool(): MetricoolPublisher
    {
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            throw new RuntimeException(
                'PUBLISH_PROVIDER=metricool but Metricool is not configured '
                . '(METRICOOL_API_TOKEN / METRICOOL_USER_ID empty or unresolved). '
                . 'Provision the secret + handle, or set PUBLISH_PROVIDER=blotato to roll back.'
            );
        }
        return new MetricoolPublisher($client);
    }
}
