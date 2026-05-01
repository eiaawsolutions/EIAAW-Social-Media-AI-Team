<?php

namespace App\Agents;

/**
 * Standard result envelope every agent returns.
 *
 * `data` is the payload — its shape is per-agent (Onboarding returns a
 * brand_style.md text + voice attributes; Strategist returns calendar
 * entries). `meta` carries the receipts: prompt version, model, tokens,
 * cost, latency.
 */
final class AgentResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
    ) {}

    public static function ok(array $data, array $meta = []): self
    {
        return new self(true, $data, null, $meta);
    }

    public static function fail(string $message, array $meta = []): self
    {
        return new self(false, [], $message, $meta);
    }
}
