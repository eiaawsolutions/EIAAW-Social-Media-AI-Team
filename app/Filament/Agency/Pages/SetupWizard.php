<?php

namespace App\Filament\Agency\Pages;

use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Readiness\BrandReadiness;
use App\Services\Readiness\SetupReadiness;
use App\Services\Readiness\WorkspaceReadiness;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Setup Wizard — the verifiable readiness ladder.
 *
 * This page is the default landing for any workspace that is < 100% ready.
 * It runs SetupReadiness against Postgres on every render (cached 30s) and
 * shows the user exactly what's set up, what's still missing, and the single
 * next action they should take.
 *
 * URL: /agency/setup-wizard?brand=<id>&focus=<stage_id>
 */
class SetupWizard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Setup wizard';
    protected static ?string $title = 'Setup wizard';
    protected static ?int $navigationSort = -1; // pin to top of nav
    protected string $view = 'filament.agency.pages.setup-wizard';

    public ?int $brand = null;
    public ?string $focus = null;

    public ?WorkspaceReadiness $workspaceReadiness = null;
    public ?BrandReadiness $brandReadiness = null;

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
        $this->focus = request()->string('focus')->toString() ?: null;
        $this->refreshReadiness();
    }

    public function refreshReadiness(): void
    {
        $user = auth()->user();
        if (! $user) return;

        $workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $service = app(SetupReadiness::class);
        $this->workspaceReadiness = $service->forWorkspace($workspace);

        // Pick which brand the wizard is focused on
        if ($this->brand) {
            $brand = Brand::where('workspace_id', $workspace->id)->find($this->brand);
            if ($brand) {
                $this->brandReadiness = $service->forBrand($brand);
                return;
            }
        }

        // Default: first incomplete brand, or first brand
        $this->brandReadiness = $this->workspaceReadiness->nextActionableBrand()
            ?? $this->workspaceReadiness->primaryBrand;
    }

    public function getHeading(): string|Htmlable
    {
        if (! $this->workspaceReadiness || ! $this->workspaceReadiness->hasAnyBrand) {
            return 'Welcome — let\'s set up your first brand';
        }
        if ($this->brandReadiness?->isComplete) {
            return $this->brandReadiness->brand->name . ' — fully set up';
        }
        return $this->brandReadiness
            ? $this->brandReadiness->brand->name . ' — ' . $this->brandReadiness->percent . '% ready'
            : 'Setup wizard';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->workspaceReadiness || ! $this->workspaceReadiness->hasAnyBrand) {
            return 'Two minutes to a brand profile. Six more to your first compliant draft. Then we run.';
        }
        $next = $this->brandReadiness?->nextActionable;
        if (! $next) {
            return 'Every stage complete. The agents have everything they need to run.';
        }
        return 'Next: ' . $next->label;
    }

    /** Used by Blade view: resolves the right CSS class for a stage row. */
    public function statusClass(string $status): string
    {
        return match ($status) {
            'done' => 'wizard-stage-done',
            'blocked' => 'wizard-stage-blocked',
            default => 'wizard-stage-todo',
        };
    }

    public function statusIcon(string $status): string
    {
        return match ($status) {
            'done' => '✓',
            'blocked' => '·',
            default => '○',
        };
    }
}
