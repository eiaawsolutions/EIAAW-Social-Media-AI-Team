<?php

namespace App\Filament\Agency\Pages;

use App\Models\AutonomySetting;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Readiness\SetupReadiness;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Stage 05 — Autonomy lane decided.
 *
 * Picks the brand's default autonomy lane (green / amber / red) which the
 * Compliance gate uses to decide how a draft moves from Writer → Scheduled:
 *
 *   green  — auto-publish (no human approval)
 *   amber  — 1 human approves
 *   red    — 2 humans approve
 *
 * One row in autonomy_settings with platform IS NULL counts as the global
 * default; per-platform overrides (e.g. red on LinkedIn, green on Threads)
 * are added later via separate platform-scoped rows.
 *
 * The schema CHECK constraint enforces default_lane ∈ {green, amber, red};
 * we mirror that vocabulary here as the only legal values.
 */
class AutonomyLane extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Autonomy';
    protected static ?string $title = 'Autonomy lane';
    protected static ?int $navigationSort = 6;
    protected static ?string $slug = 'autonomy';
    protected string $view = 'filament.agency.pages.autonomy-lane';

    /** Livewire-safe scalar state. */
    public ?int $brand = null;
    public ?string $currentLane = null;

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
        $this->refreshState();
    }

    private function refreshState(): void
    {
        $brand = $this->resolveBrand();
        if (! $brand) return;

        $row = AutonomySetting::where('brand_id', $brand->id)
            ->whereNull('platform')
            ->first();
        $this->currentLane = $row?->default_lane;
    }

    public function resolveBrand(): ?Brand
    {
        $user = auth()->user();
        if (! $user) return null;

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) return null;

        if ($this->brand) {
            $b = Brand::where('workspace_id', $ws->id)->find($this->brand);
            if ($b) return $b;
        }

        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
    }

    public function pickLane(string $lane): void
    {
        if (! in_array($lane, ['green', 'amber', 'red'], true)) {
            Notification::make()->title('Unknown lane')->danger()->send();
            return;
        }

        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to set')->danger()->send();
            return;
        }

        AutonomySetting::updateOrCreate(
            ['brand_id' => $brand->id, 'platform' => null],
            ['default_lane' => $lane],
        );

        app(SetupReadiness::class)->invalidate($brand);
        $this->currentLane = $lane;

        Notification::make()
            ->title('Default lane set')
            ->body(sprintf(
                '%s lane is now your default for %s. You can override per platform later.',
                ucfirst($lane),
                $brand->name,
            ))
            ->success()
            ->send();
    }
}
