<?php

namespace App\Agents;

use App\Concerns\RequiresReadiness;
use App\Models\AuditLogEntry;
use App\Models\Brand;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Disk that branded post artifacts (composited images/videos) must be
     * published to so their URL is durable and publicly fetchable.
     *
     * R2 when the bucket is configured (production), else the local `public`
     * disk for local dev. Mirrors ManageBrandAssets::resolvePreferredDisk() and
     * DraftResource::preferredUploadDisk() — the same proven selector the
     * customer-upload path already uses.
     *
     * Why this exists: the compositor paths in DesignerAgent/VideoAgent used to
     * hardcode `public` and hand-build a `<APP_URL>/storage/branding/…` URL.
     * On Railway the public disk is ephemeral and unserved (no storage:link),
     * so that URL 404s — drafts showed "Media preview unavailable" and
     * Metricool's normalize endpoint couldn't fetch the media at publish time.
     * Regenerating just re-wrote the same dead URL. R2 ([[brand-asset-storage-
     * ephemeral]], live since 2026-06-08) serves these durably at
     * smt-assets.eiaawsolutions.com.
     */
    public static function durableArtifactDisk(): string
    {
        return config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
    }

    /**
     * Publish a locally-composited branded artifact to the durable disk and
     * return its public URL (from the disk driver — for R2 the custom-domain
     * URL, never a hand-built /storage/ path).
     *
     * @param  string  $localPath     absolute path to the composited file on the worker FS
     * @param  string  $relativePath  destination key on the disk, e.g. "branding/388-abc.jpg"
     * @return string  publicly fetchable URL for the stored artifact
     */
    protected function publishArtifact(string $localPath, string $relativePath): string
    {
        $disk = self::durableArtifactDisk();
        $storage = Storage::disk($disk);
        $storage->put($relativePath, file_get_contents($localPath), 'public');

        return $storage->url($relativePath);
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
