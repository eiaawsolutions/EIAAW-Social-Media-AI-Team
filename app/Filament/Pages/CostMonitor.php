<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CostMonitorStats;
use App\Services\Monitoring\CostMonitor as CostMonitorService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * HQ Cost Monitor — what it costs to run SMT this month, and the profit left
 * after every expense, updating live as workspaces sign up and agents run.
 *
 * Layout:
 *   - four polling headline stats (revenue / cost / profit / margin) via the
 *     CostMonitorStats header widget,
 *   - a full line-by-line breakdown table (revenue by plan, AI by provider,
 *     Blotato, each fixed line) rendered by the Blade view, which also polls.
 *
 * Every figure is sourced by App\Services\Monitoring\CostMonitor from real
 * tables + the operator-set cost register; operator-set lines are tagged in
 * the UI and honesty warnings surface when a cost is still unset, so the
 * profit number is never silently overstated.
 *
 * Admin (HQ) panel, super-admin only — same gate as the other HQ-only pages.
 */
class CostMonitor extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Cost monitor';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3; // below Onboarding journey (sort 2)

    protected static ?string $title = 'Cost monitor';

    protected static ?string $slug = 'cost-monitor';

    protected string $view = 'filament.pages.cost-monitor';

    /** Selected month anchor (ISO date string), bound from the month picker. */
    public ?string $monthAnchor = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function mount(): void
    {
        $this->monthAnchor ??= now()->startOfMonth()->toDateString();
    }

    public function getSubheading(): ?string
    {
        return 'Live running cost and profit for SMT. Variable AI spend is measured from the ledger; '
            .'fixed infra and the Blotato seat rate are operator-set in config/costs.php and tagged below. '
            .'Numbers refresh automatically every 30 seconds.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CostMonitorStats::class,
        ];
    }

    /**
     * Inject the selected month into the header stats widget so the headline
     * numbers track the month picker (Filament binds matching public props on
     * header widgets from this array).
     */
    public function getWidgetData(): array
    {
        return [
            'monthAnchor' => $this->monthAnchor,
        ];
    }

    /** Month options for the picker — current month plus the previous 11. */
    public function monthOptions(): array
    {
        $options = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $m = $cursor->copy()->subMonths($i);
            $options[$m->toDateString()] = $m->format('F Y');
        }

        return $options;
    }

    /**
     * The full snapshot for the selected month — drives the breakdown view.
     * Plain method (called as $this->snapshot() in Blade), matching the
     * getBoard()/getSlides() convention used by the other HQ pages.
     */
    public function snapshot(): array
    {
        $anchor = $this->monthAnchor
            ? Carbon::parse($this->monthAnchor)
            : now();

        return app(CostMonitorService::class)->snapshot($anchor);
    }
}
