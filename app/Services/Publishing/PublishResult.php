<?php

namespace App\Services\Publishing;

/**
 * Provider-agnostic outcome of a publish attempt. Both BlotatoPublisher and
 * MetricoolPublisher return this so SubmitScheduledPost is ignorant of which
 * backend ran — the seam that makes PUBLISH_PROVIDER a config flag, not a
 * rewrite.
 *
 * Discriminated by `state`:
 *   'submitted'  → provider accepted the post; `providerPostId` is its handle
 *                  for later status polling. Not yet confirmed live.
 *   'published'  → confirmed live on the platform; `platformPostUrl` (and
 *                  optionally `platformPostId`) are set and have passed
 *                  PostVerificationRules.
 *   'failed'     → rejected; `error` explains why.
 *   'pending'    → accepted but not yet verifiable; poll again later. (Same as
 *                  staying in `submitted` for the job's state machine.)
 */
final class PublishResult
{
    private function __construct(
        public readonly string $state,
        public readonly ?string $providerPostId = null,
        public readonly ?string $platformPostId = null,
        public readonly ?string $platformPostUrl = null,
        public readonly ?string $error = null,
        public readonly ?array $raw = null,
    ) {}

    public static function submitted(string $providerPostId, ?array $raw = null): self
    {
        return new self(state: 'submitted', providerPostId: $providerPostId, raw: $raw);
    }

    public static function published(?string $platformPostId, ?string $platformPostUrl, ?array $raw = null): self
    {
        return new self(
            state: 'published',
            platformPostId: $platformPostId,
            platformPostUrl: $platformPostUrl,
            raw: $raw,
        );
    }

    public static function failed(string $error, ?array $raw = null): self
    {
        return new self(state: 'failed', error: $error, raw: $raw);
    }

    public static function pending(?string $providerPostId = null, ?array $raw = null): self
    {
        return new self(state: 'pending', providerPostId: $providerPostId, raw: $raw);
    }
}
