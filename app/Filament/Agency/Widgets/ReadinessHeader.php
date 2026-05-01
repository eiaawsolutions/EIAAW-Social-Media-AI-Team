<?php

namespace App\Filament\Agency\Widgets;

use App\Services\Readiness\SetupReadiness;
use App\Services\Readiness\WorkspaceReadiness;
use Filament\Widgets\Widget;

/**
 * The persistent "% ready" widget shown at the top of every page in the agency
 * panel. Single line summary + a one-click jump to the wizard's next action.
 */
class ReadinessHeader extends Widget
{
    protected string $view = 'filament.agency.widgets.readiness-header';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -100;

    public function getViewData(): array
    {
        $user = auth()->user();
        if (! $user) return ['readiness' => null];

        $workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $workspace) return ['readiness' => null];

        /** @var WorkspaceReadiness $readiness */
        $readiness = app(SetupReadiness::class)->forWorkspace($workspace);
        return ['readiness' => $readiness];
    }
}
