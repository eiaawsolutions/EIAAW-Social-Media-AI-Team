<?php

namespace App\Services\Readiness;

use App\Models\Workspace;

/**
 * Workspace-level readiness: aggregates BrandReadiness across all brands +
 * adds workspace-only stages (Blotato account provisioned, billing active).
 *
 * Workspace stages are detected in SetupReadiness::forWorkspace() and passed
 * in as the $platformStage argument. They render ABOVE the per-brand ladder
 * in the wizard because they are blockers for every brand in the workspace.
 */
final class WorkspaceReadiness
{
    /** @var BrandReadiness[] */
    public readonly array $brands;
    public readonly int $brandCount;
    public readonly int $brandsComplete;
    public readonly int $aggregatePercent;
    public readonly bool $hasAnyBrand;
    public readonly ?BrandReadiness $primaryBrand;
    public readonly ?ReadinessStage $platformStage;
    public readonly bool $platformReady;

    public function __construct(
        public readonly Workspace $workspace,
        ?ReadinessStage $platformStage,
        BrandReadiness ...$brands,
    ) {
        $this->platformStage = $platformStage;
        $this->platformReady = $platformStage === null || $platformStage->done;
        $this->brands = $brands;
        $this->brandCount = count($brands);
        $this->brandsComplete = count(array_filter($brands, fn ($b) => $b->isComplete));
        $this->hasAnyBrand = $this->brandCount > 0;
        $this->aggregatePercent = $this->brandCount > 0
            ? (int) round(array_sum(array_map(fn ($b) => $b->percent, $brands)) / $this->brandCount)
            : 0;
        $this->primaryBrand = $brands[0] ?? null;
    }

    public function nextActionableBrand(): ?BrandReadiness
    {
        // First incomplete brand wins focus
        foreach ($this->brands as $b) {
            if (! $b->isComplete) {
                return $b;
            }
        }
        return null;
    }

    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'platform_stage' => $this->platformStage?->toArray(),
            'platform_ready' => $this->platformReady,
            'has_any_brand' => $this->hasAnyBrand,
            'brand_count' => $this->brandCount,
            'brands_complete' => $this->brandsComplete,
            'aggregate_percent' => $this->aggregatePercent,
            'brands' => array_map(fn ($b) => $b->toArray(), $this->brands),
        ];
    }
}
