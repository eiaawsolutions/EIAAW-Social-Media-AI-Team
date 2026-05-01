<?php

namespace App\Services\Readiness;

use App\Models\Brand;
use App\Models\BrandStyle;
use App\Models\BrandCorpusItem;
use App\Models\PlatformConnection;
use App\Models\AutonomySetting;
use App\Models\ContentCalendar;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\PerformanceUpload;
use Illuminate\Support\Carbon;

/**
 * Per-brand readiness result. Computed once per request via SetupReadiness::for().
 *
 * Holds 9 ReadinessStage rows + aggregate computed fields (percent, next_actionable,
 * is_complete) so views and gates query a single object.
 */
final class BrandReadiness
{
    /** @var ReadinessStage[] */
    public readonly array $stages;
    public readonly int $totalStages;
    public readonly int $doneStages;
    public readonly int $percent;
    public readonly ?ReadinessStage $nextActionable;
    public readonly bool $isComplete;

    public function __construct(
        public readonly Brand $brand,
        ReadinessStage ...$stages,
    ) {
        usort($stages, fn ($a, $b) => $a->order <=> $b->order);
        $this->stages = $stages;
        $this->totalStages = count($stages);
        $this->doneStages = count(array_filter($stages, fn ($s) => $s->done));
        $this->percent = $this->totalStages > 0
            ? (int) floor($this->doneStages / $this->totalStages * 100)
            : 0;
        $this->nextActionable = $this->findNextActionable($stages);
        $this->isComplete = $this->doneStages === $this->totalStages;
    }

    /** Find the lowest-order incomplete stage that is not blocked by a prereq. */
    private function findNextActionable(array $stages): ?ReadinessStage
    {
        foreach ($stages as $s) {
            if (! $s->done && $s->blockedBy === null) {
                return $s;
            }
        }
        return null;
    }

    /** Used by agent jobs: throws if a required stage isn't done. */
    public function requireStage(string $id): void
    {
        $stage = collect($this->stages)->firstWhere('id', $id);
        if (! $stage || ! $stage->done) {
            throw new \App\Exceptions\AgentPrerequisiteMissing(
                "Required setup stage not complete: {$id}",
                ['brand_id' => $this->brand->id, 'missing_stage' => $id]
            );
        }
    }

    public function toArray(): array
    {
        return [
            'brand_id' => $this->brand->id,
            'brand_name' => $this->brand->name,
            'percent' => $this->percent,
            'done_stages' => $this->doneStages,
            'total_stages' => $this->totalStages,
            'is_complete' => $this->isComplete,
            'next_actionable' => $this->nextActionable?->toArray(),
            'stages' => array_map(fn ($s) => $s->toArray(), $this->stages),
        ];
    }
}
