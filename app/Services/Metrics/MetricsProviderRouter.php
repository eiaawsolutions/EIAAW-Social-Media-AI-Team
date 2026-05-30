<?php

namespace App\Services\Metrics;

use App\Models\ScheduledPost;
use App\Services\Meta\MetaGraphClient;
use App\Services\Metricool\MetricoolClient;

/**
 * Picks the metrics provider for a given published post, then collects.
 *
 * This is the SEAM that keeps CollectPostMetrics ignorant of which backend
 * supplied the numbers. Provider precedence (first that applies wins):
 *
 *   1. Meta Graph (first-party) — HQ's OWN Instagram/Facebook posts, when a
 *      Business Manager System User token is configured. Real metrics now,
 *      no Meta App Review (Standard Access covers owned accounts).
 *   2. Metricool — any post whose brand is mapped to a Metricool blogId
 *      (brands.metricool_blog_id) and Metricool is configured. This is the
 *      WORKING per-post analytics path the Blotato→Metricool switch added;
 *      verified live 2026-05-30 (memory metricool-field-map). One shared
 *      token, brand scoped by blogId.
 *   3. Blotato analytics (dormant) — fallback for anything else. Records
 *      "no data yet" until Blotato's analytics backend ships.
 *
 * Plus the CSV upload fallback (operator-driven at /agency/performance, not
 * routed here) for posts no provider can report on.
 *
 * FUTURE (customer Meta OAuth): when per-customer Meta tokens land, the Meta
 * branch grows to build MetaGraphClient from the per-connection token. The
 * branch goes HERE, not in the job.
 *
 * Selection is conservative and never leaves a post provider-less: Meta only
 * for HQ-owned IG/FB with a token; Metricool only when the brand has a blogId
 * AND the integration is configured; otherwise Blotato (which degrades to a
 * no-data snapshot).
 */
class MetricsProviderRouter
{
    public function __construct(private readonly BlotatoMetricsCollector $blotato) {}

    /**
     * Resolve the provider and collect. Returns the collector's discriminated
     * result verbatim (see Meta / Metricool / Blotato collectors).
     *
     * @return array<string,mixed>
     */
    public function collect(ScheduledPost $post): array
    {
        $meta = $this->metaCollectorFor($post);
        if ($meta !== null) {
            return $meta->collect($post);
        }

        $metricool = $this->metricoolCollectorFor($post);
        if ($metricool !== null) {
            return $metricool->collect($post);
        }

        return $this->blotato->collect($post);
    }

    /**
     * Returns a MetricoolMetricsCollector when this post's brand is mapped to a
     * Metricool blogId and Metricool is configured, else null (caller falls
     * back to Blotato). The collector itself re-checks blogId per post, so this
     * gate is the cheap "is Metricool even in play?" short-circuit.
     */
    private function metricoolCollectorFor(ScheduledPost $post): ?MetricoolMetricsCollector
    {
        if (! $post->brand?->metricool_blog_id) {
            return null;
        }

        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            return null; // integration not configured → fall back
        }

        return new MetricoolMetricsCollector($client);
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
