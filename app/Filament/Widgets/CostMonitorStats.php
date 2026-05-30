<?php

namespace App\Filament\Widgets;

use App\Services\Monitoring\CostMonitor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * The four headline numbers of the HQ Cost Monitor: this month's revenue, this
 * month's running cost, net profit, and margin. Polls every 30s (CanPoll) so
 * the figures move as workspaces sign up and agents run — the "live update
 * based on live signups" the brief asked for.
 *
 * All figures come from App\Services\Monitoring\CostMonitor, which reads real
 * tables (workspaces, ai_costs) + the operator-set cost register. Nothing here
 * is fabricated; the page below this widget shows the full line-by-line breakdown.
 */
class CostMonitorStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    /** Render only on the Cost Monitor page (not the HQ dashboard). */
    protected static bool $isLazy = false;

    /**
     * Injected by the Cost Monitor page (getWidgetData) so the headline numbers
     * follow the month picker. Null = current month.
     */
    public ?string $monthAnchor = null;

    /**
     * This widget lives in the auto-discovered Filament\Widgets directory, so
     * without a guard it would also appear on the HQ dashboard for everyone.
     * Restrict it to super-admins viewing the Cost Monitor page — it is only
     * ever rendered there as a header widget. (Header widgets are filtered by
     * canView() too, so the route check must allow the Cost Monitor page.)
     */
    public static function canView(): bool
    {
        if (! (bool) auth()->user()?->is_super_admin) {
            return false;
        }

        return request()->routeIs('filament.admin.pages.cost-monitor');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $month = $this->monthAnchor ? Carbon::parse($this->monthAnchor) : null;
        $s = app(CostMonitor::class)->snapshot($month);

        $revenue = $s['revenue']['total_myr'];
        $cost = $s['costs']['total_myr'];
        $profit = $s['profit']['net_myr'];
        $margin = $s['profit']['margin_pct'];

        $profitPositive = $profit >= 0;

        return [
            Stat::make('Revenue · '.$s['period']['label'], 'RM '.$this->money($revenue))
                ->description($s['signups']['paying'].' paying workspace(s) live')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Running cost', 'RM '.$this->money($cost))
                ->description('AI RM '.$this->money($s['costs']['ai_myr'])
                    .' · Metricool RM '.$this->money($s['costs']['metricool_myr'])
                    .($s['costs']['railway'] ? ' · Railway RM '.$this->money($s['costs']['railway']['amount_myr']) : '')
                    .' · fixed RM '.$this->money($s['costs']['fixed_myr']))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('Net profit', 'RM '.$this->money($profit))
                ->description($profitPositive ? 'After all expenses' : 'Operating at a loss')
                ->descriptionIcon($profitPositive ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($profitPositive ? 'success' : 'danger'),

            Stat::make('Margin', $margin === null ? '—' : $margin.'%')
                ->description($margin === null ? 'No revenue yet' : 'Profit ÷ revenue')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color($margin === null ? 'gray' : ($margin >= 50 ? 'success' : ($margin >= 0 ? 'warning' : 'danger'))),
        ];
    }

    private function money(float $v): string
    {
        return number_format($v, 2);
    }
}
