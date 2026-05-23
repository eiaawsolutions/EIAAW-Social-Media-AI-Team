<?php

namespace App\Filament\Agency\Pages;

use App\Services\Monitoring\AgentTelemetry;
use Filament\Pages\Page;

/**
 * Agents monitor — cross-workspace operational view of every agent in
 * app/Agents/, in the order they appear in the pipeline. Shows each
 * agent's current status (stuck / failing / active / healthy / idle),
 * the reason if it's not healthy, and a concrete next-best-action.
 *
 * Nav placement: under Billing (sort 91) per operator request. Visibility
 * is restricted to super-admin (EIAAW staff) because the data is cross-
 * workspace and operators of a single workspace shouldn't see other
 * tenants' run counts.
 *
 * Data source: app/Services/Monitoring/AgentTelemetry — derives status
 * from audit_log + pipeline_runs + Horizon, soft-failing on Redis when
 * Horizon is unreachable. No new tables; no scheduled rebuild; the page
 * computes its snapshot on each render.
 */
class AgentsMonitor extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'Agents';
    protected static ?string $title = 'Agents monitor';
    protected static ?int $navigationSort = 91;
    protected static ?string $slug = 'agents';
    protected string $view = 'filament.agency.pages.agents-monitor';

    public function getSubheading(): ?string
    {
        return 'Every agent in the pipeline, in order. Status, blockers, and what to do about each one.';
    }

    /**
     * Super-admin gate. Mirrors HorizonServiceProvider::gate() — the data on
     * this page is cross-workspace, so it must never render for regular
     * operators.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && (bool) ($user->is_super_admin ?? false);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public array $horizonInfo = ['available' => true, 'error' => null];
    public ?string $generatedAt = null;

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->refresh();
    }

    public function refresh(): void
    {
        $telemetry = app(AgentTelemetry::class);
        $this->rows = $telemetry->snapshot();
        // Surface a single Horizon health line at the top of the page —
        // taken from the first row's reach attempt (all rows share one
        // workload snapshot, so it doesn't matter which we read).
        $this->horizonInfo = $this->detectHorizonInfo();
        $this->generatedAt = now()->toIso8601String();
    }

    private function detectHorizonInfo(): array
    {
        // Replay the same call AgentTelemetry uses, so the banner matches
        // what the rows saw. Cheap — one Redis ping or a fast fail.
        if (! class_exists(\Laravel\Horizon\Contracts\WorkloadRepository::class)) {
            return ['available' => false, 'error' => 'Horizon package not installed.'];
        }
        try {
            app(\Laravel\Horizon\Contracts\WorkloadRepository::class)->get();
            return ['available' => true, 'error' => null];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => 'Horizon/Redis unreachable: ' . \Illuminate\Support\Str::limit($e->getMessage(), 140),
            ];
        }
    }
}
