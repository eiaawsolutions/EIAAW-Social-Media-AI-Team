<?php

namespace App\Agents;

use App\Concerns\RequiresReadiness;
use App\Models\AuditLogEntry;
use App\Models\Brand;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Facades\Log;

/**
 * Base for all 6 EIAAW agents. Handles:
 *  - LLM gateway injection
 *  - Readiness gating (each subclass declares its required stages)
 *  - Audit-log entry on every run
 *
 * Subclasses implement `handle(Brand $brand, array $input): AgentResult`.
 */
abstract class BaseAgent
{
    use RequiresReadiness;

    /** Human-readable name shown in audit log + telemetry. */
    abstract public function role(): string;

    /** Identifier the prompt sub-class versions through. */
    abstract public function promptVersion(): string;

    /** Subclass logic — return AgentResult. */
    abstract protected function handle(Brand $brand, array $input): AgentResult;

    public function __construct(
        protected readonly LlmGateway $llm,
    ) {}

    /**
     * Public entry point. Don't override — override `handle()` instead.
     */
    public function run(Brand $brand, array $input = []): AgentResult
    {
        $this->ensureReady($brand);
        $startedAt = hrtime(true);

        try {
            $result = $this->handle($brand, $input);
        } catch (\Throwable $e) {
            Log::error('Agent failed', [
                'agent' => $this->role(),
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            $this->logAudit($brand, 'failed', ['error' => substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $this->logAudit($brand, $result->ok ? 'completed' : 'failed', [
            'meta' => $result->meta,
            'latency_ms' => $latencyMs,
            'error' => $result->errorMessage,
        ]);

        return $result;
    }

    private function logAudit(Brand $brand, string $outcome, array $context): void
    {
        try {
            AuditLogEntry::create([
                'workspace_id' => $brand->workspace_id,
                'brand_id' => $brand->id,
                'actor_user_id' => auth()->id(),
                'actor_type' => auth()->id() ? 'user' : 'agent',
                'action' => 'agent.'.$this->role().'.'.$outcome,
                'subject_type' => Brand::class,
                'subject_id' => $brand->id,
                'context' => $context,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed', ['error' => $e->getMessage()]);
        }
    }
}
