<?php

namespace App\Services\Readiness;

use App\Models\Workspace;

/**
 * Workspace-level readiness: aggregates BrandReadiness across all brands +
 * adds workspace-only stages (workspace created, at least one member, billing).
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

    public function __construct(
        public readonly Workspace $workspace,
        BrandReadiness ...$brands,
    ) {
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
            'has_any_brand' => $this->hasAnyBrand,
            'brand_count' => $this->brandCount,
            'brands_complete' => $this->brandsComplete,
            'aggregate_percent' => $this->aggregatePercent,
            'brands' => array_map(fn ($b) => $b->toArray(), $this->brands),
        ];
    }
}
