<?php

namespace App\Services\Llm;

use Anthropic\Client;
use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Security\DetectorVerdict;
use App\Services\Security\InjectionContext;
use App\Services\Security\PromptInjectionDetector;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Detector + logger are injected lazily via app() inside call() rather
     * than the constructor — Laravel resolves LlmGateway as a singleton in
     * a lot of call sites, and the detector itself depends on this gateway
     * (the L2 grader uses it). Lazy resolution breaks the cycle. The fact
     * that we don't construct them upfront also means a security path
     * outage (e.g. Redis down for the throttle) can't break gateway DI.
     */
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
        string $inputSurface = 'user_input',
    ): LlmCallResult {
        $modelId = $modelId ?: config('services.anthropic.default_model', 'claude-sonnet-4-6');

        if (empty(config('services.anthropic.api_key'))) {
            throw new RuntimeException(
                'Anthropic API key not configured. Set ANTHROPIC_API_KEY in your env (or via Infisical handle).'
            );
        }

        // Correlation id binds the input scan + the output canary + the
        // ai_costs row + Horizon job log together for forensics.
        $correlationId = (string) Str::uuid();

        // ── Pre-flight: prompt-injection detector on the input ──────────
        // Skip when the call IS the detector's own grader (avoid recursion)
        // or when the detector is disabled globally.
        $shouldDetect = $agentRole !== 'security.injection_grader'
            && (bool) config('security.injection_detector.enabled', true);

        if ($shouldDetect) {
            $inputContext = new InjectionContext(
                surface: $inputSurface,
                text: $userMessage,
                agentRole: $agentRole,
                workspace: $workspace,
                brand: $brand,
                correlationId: $correlationId,
                modelId: $modelId,
                promptVersion: $promptVersion,
            );

            $blocked = $this->processDetectorVerdict($inputContext);
            if ($blocked) {
                // Generic exception — never leak the verdict to the caller.
                // The caller (and its caller) only learn that the request
                // failed. The full reason lives in security_events.
                throw new RuntimeException('LLM call blocked by safety check.');
            }
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

        // ── Output canary: scan the model's response for leaks ──────────
        // The canary runs heuristic-only (no grader recursion) and never
        // blocks — if the model leaked, the bytes are already out of the
        // gateway. The point is detection + alerting, not prevention.
        if ($shouldDetect && $rawText !== '') {
            $outputContext = new InjectionContext(
                surface: 'agent_output',
                text: $rawText,
                agentRole: $agentRole,
                workspace: $workspace,
                brand: $brand,
                correlationId: $correlationId,
                modelId: $modelId,
                promptVersion: $promptVersion,
            );
            // Run-and-log only; ignore the verdict for control flow.
            $this->processDetectorVerdict($outputContext, blockOnHigh: false);
        }

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
            stopReason: $this->extractStopReason($response),
            rawResponse: [],
        );
    }

    /**
     * Runs the detector, persists the event, and returns true if the
     * caller should block this LLM call.
     *
     * The detector + logger are resolved lazily from the container so a
     * security-stack outage (Redis down, Resend unreachable) can't break
     * gateway construction.
     *
     * `$blockOnHigh` lets the output canary opt out of blocking — at that
     * point the bytes have already left Anthropic; we just want detection.
     */
    private function processDetectorVerdict(InjectionContext $context, bool $blockOnHigh = true): bool
    {
        try {
            /** @var PromptInjectionDetector $detector */
            $detector = app(PromptInjectionDetector::class);
            $verdict = $detector->evaluate($context);
        } catch (\Throwable $e) {
            // Detector itself crashed. Don't block the call, but log so
            // we know our security path is degraded.
            Log::error('LlmGateway: injection detector crashed', [
                'surface' => $context->surface,
                'agent' => $context->agentRole,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($verdict->verdict === DetectorVerdict::VERDICT_SAFE) {
            return false;
        }

        $enforce = (bool) config('security.injection_detector.enforce', true);
        $shouldBlock = $blockOnHigh && $enforce && $verdict->isBlockable();

        try {
            /** @var SecurityEventLogger $logger */
            $logger = app(SecurityEventLogger::class);
            $logger->record($context, $verdict, blocked: $shouldBlock);
        } catch (\Throwable $e) {
            Log::error('LlmGateway: failed to log security event', [
                'error' => $e->getMessage(),
            ]);
            // Persistence failed — but we still honor the block decision.
            // Better to deny a possibly-bad call than to let it through
            // because the ledger was unreachable.
        }

        return $shouldBlock;
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
        } catch (\Throwable) {
            // The model produced text that wasn't JSON — surface raw text only.
            Log::warning('LlmGateway: structured output requested but response was not parseable', [
                'sample' => substr($trimmed, 0, 200),
            ]);
            return null;
        }
    }

    /**
     * Anthropic SDK's BaseModel overrides ->stopReason / ->model in some
     * cases — array access is the safe path. Fall back gracefully.
     */
    private function extractStopReason(object $response): ?string
    {
        try {
            return $response['stop_reason'] ?? $response->stopReason ?? null;
        } catch (\Throwable) {
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
