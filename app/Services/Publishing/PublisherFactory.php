<?php

namespace App\Services\Publishing;

use App\Services\Metricool\MetricoolClient;
use RuntimeException;

/**
 * Builds the publishing provider. Metricool is the sole publisher since the
 * Blotato decommission — this factory remains as the single construction point
 * (and the seam where a future provider could slot in) rather than `new
 * MetricoolPublisher` scattered across the codebase.
 *
 * If Metricool isn't configured we throw loudly rather than guess — a
 * misconfigured publish provider must surface, never silently no-op.
 */
class PublisherFactory
{
    public function make(): Publisher
    {
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            throw new RuntimeException(
                'Metricool is not configured (METRICOOL_API_TOKEN / METRICOOL_USER_ID '
                . 'empty or unresolved). Provision the secret + handle in Infisical.'
            );
        }
        return new MetricoolPublisher($client);
    }
}
