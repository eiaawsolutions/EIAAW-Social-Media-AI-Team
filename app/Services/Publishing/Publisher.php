<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;

/**
 * Provider-agnostic publishing contract. Implementations: BlotatoPublisher
 * (legacy, dormant after the Metricool switch) and MetricoolPublisher (the
 * new default). Selected per-request by PublisherFactory based on the
 * PUBLISH_PROVIDER config flag.
 *
 * The two methods mirror SubmitScheduledPost's existing two phases:
 *   submit()  — push the assembled post to the provider, return a handle.
 *   poll()    — check status of an already-submitted post, return whether it's
 *               confirmed live (with the verified platform URL/id).
 *
 * Both take the fully-loaded ScheduledPost (with draft + platformConnection +
 * brand.workspace eager-loaded) and return a PublishResult. Caption assembly,
 * plan-cap checks, kill-switch, and PlatformRules gating stay in the JOB
 * (provider-independent); only the provider call + status interpretation live
 * here.
 */
interface Publisher
{
    /**
     * Submit a fresh post to the provider. The job has already assembled the
     * caption and validated publishability; the publisher uploads/normalises
     * media, builds the provider-specific body, and calls the provider.
     *
     * @param  string             $caption  fully-assembled caption (body+tags+mentions)
     * @param  array<int,string>  $mediaUrls source media URLs (the publisher
     *                                        re-hosts/normalises as the provider needs)
     */
    public function submit(ScheduledPost $post, string $caption, array $mediaUrls): PublishResult;

    /**
     * Poll the status of an already-submitted post and return whether it is
     * verified-published. Implementations apply PostVerificationRules before
     * reporting `published` (never trust a bare provider "published" flag).
     */
    public function poll(ScheduledPost $post): PublishResult;

    /** Short provider key for logging / the source column ('blotato'|'metricool'). */
    public function key(): string;
}
