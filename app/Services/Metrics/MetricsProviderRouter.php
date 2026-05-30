<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Meta\MetaGraphClient;

/**
 * Picks the metrics provider for a given published post, then collects.
 *
 * This is the SEAM that keeps CollectPostMetrics ignorant of which backend
 * supplied the numbers. Today there are two real providers plus the CSV
 * fallback (operator-driven, not routed here):
 *
 *   1. Meta Graph (first-party) — HQ's OWN Instagram/Facebook posts, when a
 *      Business Manager System User token is configured. Real metrics now,
 *      no Meta App Review (Standard Access covers owned accounts).
 *   2. Blotato analytics (dormant) — everything else. Records "no data yet"
 *      until Blotato's analytics backend ships, then flows automatically.
 *
 * FUTURE (customer accounts): when per-customer Meta OAuth lands, this router
 * grows a branch — "customer IG/FB post + that workspace has a stored,
 * unexpired Meta token → MetaGraphClient built from the per-connection token."
 * Nothing else in the pipeline changes. The branch goes HERE, not in the job.
 *
 * Selection is deliberately conservative: a post only routes to Meta when ALL
 * hold — platform is IG/FB, the owning workspace is internal (HQ), and an HQ
 * System User token is actually present. Any miss → Blotato (which itself
 * degrades to a no-data snapshot). No post is ever left with no provider.
 */
class MetricsProviderRouter
{
    public function __construct(private readonly BlotatoMetricsCollector $blotato) {}

    /**
     * Resolve the provider and collect. Returns the collector's discriminated
     * result verbatim (see BlotatoMetricsCollector / MetaMetricsCollector).
     *
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        $meta = $this->metaCollectorFor($post);
        if ($meta !== null) {
            return $meta->collect($post);
        }

        return $this->blotato->collect($post);
    }

    /**
     * Returns a MetaMetricsCollector when this post should be served by Meta's
     * first-party Graph API, else null (caller falls back to Blotato).
     */
    private function metaCollectorFor(ScheduledPost $post): ?MetaMetricsCollector
    {
        $platform = (string) ($post->draft?->platform ?? '');
        if (! in_array($platform, MetaMetricsCollector::PLATFORMS, true)) {
            return null;
        }

        // Phase 1: HQ-owned accounts only. Customer posts wait for the
        // per-customer OAuth phase (the future branch noted in the docblock).
        $workspace = $post->brand?->workspace;
        if (! $workspace || ! $workspace->is_internal) {
            return null;
        }

        // Need the HQ System User token. Absent (not yet provisioned) → null,
        // so the feature ships safely dark and lights up when the token lands.
        $client = MetaGraphClient::hqFromConfig();
        if ($client === null) {
            return null;
        }

        return new MetaMetricsCollector($client);
    }
}
