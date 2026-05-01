<?php

namespace App\Services\Llm;

/**
 * Structured result of one LLM call. Every agent receives one of these and
 * persists the full provenance into drafts/compliance_checks/ai_costs.
 */
final class LlmCallResult
{
    public function __construct(
        public readonly string $modelId,           // claude-sonnet-4-6
        public readonly string $promptVersion,     // writer.linkedin.v3.2
        public readonly string $rawText,           // full text response from Claude
        public readonly ?array $parsedJson,        // if structured output requested
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
        public readonly float $costUsd,
        public readonly ?string $stopReason,
        public readonly array $rawResponse = [],   // full SDK response for debugging
    ) {}

    public function isComplete(): bool
    {
        return in_array($this->stopReason, ['end_turn', 'tool_use', null], true);
    }

    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
