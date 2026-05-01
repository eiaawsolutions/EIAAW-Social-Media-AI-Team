<?php

namespace App\Services\Llm;

use Anthropic\Client;
use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single point of contact between EIAAW agents and Anthropic.
 *
 * Every agent goes through this — that gives us:
 * - One place to enforce cost limits + circuit breakers
 * - One place to track per-call provenance (cost, latency, model, prompt_version)
 * - One place to swap implementations (Anthropic API ↔ Bedrock ↔ self-hosted)
 * - Per-tenant cost ledger for the transparent pass-through that's part of the
 *   product promise.
 *
 * Call sites pass:
 *   - $promptVersion: e.g. "writer.linkedin.v3.2" — versioned identifier the
 *     agent owns (we store it on every Draft + ai_cost row for replay debugging).
 *   - $systemPrompt: the role / behaviour spec (frozen per agent + prompt version).
 *   - $userMessage: the per-call input.
 *   - $jsonSchema: optional — when present, response is parsed with json_schema.
 *
 * Cost calculation uses the Anthropic published rates (USD per 1M tokens)
 * cached at the top of this file. Numbers refresh when models change.
 */
class LlmGateway
{
    /** @var array<string, array{input: float, output: float}> USD per 1M tokens */
    private const PRICING = [
        'claude-opus-4-7'           => ['input' => 5.00,  'output' => 25.00],
        'claude-opus-4-6'           => ['input' => 5.00,  'output' => 25.00],
        'claude-sonnet-4-6'         => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4-5-20251001' => ['input' => 1.00,  'output' => 5.00],
        'claude-haiku-4-5'          => ['input' => 1.00,  'output' => 5.00],
    ];

    private ?Client $client = null;

    public function __construct() {}

    /**
     * Single-shot text generation. Returns the raw text + provenance.
     *
     * Agent contract:
     *   - $promptVersion is the agent's own versioned identifier ("writer.linkedin.v3.2")
     *     and is what shows up on the Draft.prompt_version column.
     *   - $brand and $workspace are required because every call writes an ai_costs row
     *     for transparent pass-through. Pass null for both ONLY in system contexts (boot
     *     warmup, evals) — the cost is then lost from the per-tenant ledger.
     */
    public function call(
        string $promptVersion,
        string $systemPrompt,
        string $userMessage,
        ?Brand $brand = null,
        ?Workspace $workspace = null,
        ?string $modelId = null,
        int $maxTokens = 4096,
        ?array $jsonSchema = null,
        string $agentRole = 'unknown',
    ): LlmCallResult {
        $modelId = $modelId ?: config('services.anthropic.default_model', 'claude-sonnet-4-6');

        if (empty(config('services.anthropic.api_key'))) {
            throw new RuntimeException(
                'Anthropic API key not configured. Set ANTHROPIC_API_KEY in your env (or via Infisical handle).'
            );
        }

        $params = [
            'maxTokens' => $maxTokens,
            'model' => $modelId,
            'system' => $systemPrompt,
            'messages' => [[
                'role' => 'user',
                'content' => $userMessage,
            ]],
        ];

        // When a JSON schema is provided, ask Anthropic to constrain the output.
        // This uses the platform's structured outputs feature on supported models.
        if ($jsonSchema !== null) {
            $params['outputConfig'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $jsonSchema,
                ],
            ];
        }

        $startedAt = hrtime(true);
        try {
            $response = $this->getClient()->messages->create(...$params);
        } catch (\Throwable $e) {
            Log::error('LlmGateway: API call failed', [
                'agent' => $agentRole,
                'prompt_version' => $promptVersion,
                'model' => $modelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $rawText = $this->extractText($response);
        $parsedJson = ($jsonSchema !== null) ? $this->extractJson($response, $rawText) : null;

        $usage = $this->normaliseUsage($response);
        $costUsd = $this->calculateCost($modelId, $usage['input'], $usage['output']);

        // Persist the cost ledger row. Skip if no brand/workspace context (system calls).
        if ($brand && $workspace) {
            try {
                AiCost::create([
                    'workspace_id' => $workspace->id,
                    'brand_id' => $brand->id,
                    'agent_role' => $agentRole,
                    'provider' => 'anthropic',
                    'model_id' => $modelId,
                    'input_tokens' => $usage['input'],
                    'output_tokens' => $usage['output'],
                    'cost_usd' => $costUsd,
                    'cost_myr' => round($costUsd * 4.7, 4), // simple FX, refine via daily rate later
                    'called_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Cost-ledger failure should never block the agent's work.
                Log::error('LlmGateway: cost ledger insert failed', ['error' => $e->getMessage()]);
            }
        }

        return new LlmCallResult(
            modelId: $modelId,
            promptVersion: $promptVersion,
            rawText: $rawText,
            parsedJson: $parsedJson,
            inputTokens: $usage['input'],
            outputTokens: $usage['output'],
            latencyMs: $latencyMs,
            costUsd: $costUsd,
            stopReason: $response->stopReason ?? null,
            rawResponse: [],
        );
    }

    private function getClient(): Client
    {
        if ($this->client !== null) return $this->client;
        $this->client = new Client(
            apiKey: config('services.anthropic.api_key'),
        );
        return $this->client;
    }

    /** Extract concatenated text from response.content blocks. */
    private function extractText(object $response): string
    {
        $blocks = $response->content ?? [];
        $out = '';
        foreach ($blocks as $block) {
            if (($block->type ?? null) === 'text') {
                $out .= $block->text ?? '';
            }
        }
        return $out;
    }

    /**
     * Try to extract a parsed JSON document. The SDK's structured-output mode
     * may attach a `parsed` field on the content block; otherwise we fall back
     * to parsing the raw text body, which is well-formed JSON when the schema
     * was honoured.
     */
    private function extractJson(object $response, string $rawText): ?array
    {
        $blocks = $response->content ?? [];
        foreach ($blocks as $block) {
            if (isset($block->parsed) && is_array($block->parsed)) {
                return $block->parsed;
            }
        }
        $trimmed = trim($rawText);
        if ($trimmed === '') return null;
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            // The model produced text that wasn't JSON — surface raw text only.
            Log::warning('LlmGateway: structured output requested but response was not parseable', [
                'sample' => substr($trimmed, 0, 200),
            ]);
            return null;
        }
    }

    /** @return array{input: int, output: int} */
    private function normaliseUsage(object $response): array
    {
        $usage = $response->usage ?? null;
        if (! $usage) return ['input' => 0, 'output' => 0];
        return [
            'input' => (int) ($usage->inputTokens ?? $usage->input_tokens ?? 0),
            'output' => (int) ($usage->outputTokens ?? $usage->output_tokens ?? 0),
        ];
    }

    private function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float
    {
        $pricing = self::PRICING[$modelId] ?? null;
        if (! $pricing) return 0.0;
        return round(
            ($inputTokens / 1_000_000) * $pricing['input']
            + ($outputTokens / 1_000_000) * $pricing['output'],
            6
        );
    }
}
