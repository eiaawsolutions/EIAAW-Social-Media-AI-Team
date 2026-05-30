<?php

namespace App\Services\Imagery;

use App\Models\Brand;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Facades\Log;

/**
 * Soft-failing helper that normalises (re-hosts) an external media URL so the
 * publisher accepts it. Since the Blotato decommission this routes through
 * Metricool's /actions/normalize endpoint; the class name is retained only to
 * avoid churn in CustomisedPostScheduler (which injects it).
 *
 * It remains an OPTIONAL up-front warm-up: returns the normalised reference, or
 * null on any failure — callers fall back to the original URL. MetricoolPublisher
 * normalises media again at publish time regardless, so a null here only loses
 * the warm-up, never the post.
 */
class BlotatoRehost
{
    public function forBrand(Brand $brand, string $url): ?string
    {
        // Metricool is a single shared account (not workspace-scoped like
        // Blotato was), so the brand context isn't needed to pick credentials —
        // kept in the signature for call-site compatibility.
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            return null;
        }

        try {
            $result = $client->normalizeMedia($url);
            if (! ($result['found'] ?? false)) {
                return null;
            }
            $body = $result['body'];
            if (is_string($body)) {
                return $body;
            }
            foreach (['mediaId', 'url', 'id'] as $k) {
                if (! empty($body[$k]) && is_scalar($body[$k])) {
                    return (string) $body[$k];
                }
            }
            return null;
        } catch (\Throwable $e) {
            Log::warning('BlotatoRehost: media normalize failed; using source URL', [
                'brand_id' => $brand->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
