<?php

namespace App\Services\Monitoring;

use App\Models\AiCost;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CostMonitor — the running P&L of the SMT product, in MYR.
 *
 * Computes, for a given month, what it costs EIAAW to RUN this app and what
 * profit is left after every expense. It is the brain behind the HQ Cost
 * Monitor page (App\Filament\Pages\CostMonitor) and its live widgets.
 *
 * TRUTHFULNESS CONTRACT (locked memory): no number here is invented.
 *
 *   - REVENUE is real: each live (active) PAYING workspace contributes its
 *     plan's real price from config/billing.php. Internal workspaces pay
 *     nothing and are excluded — counting them would overstate revenue.
 *
 *   - AI COST is real: summed straight from the `ai_costs` ledger, which the
 *     agents write per call (Anthropic, FAL/Veo, embeddings, TTS). We use the
 *     stored cost_myr, falling back to cost_usd × FX only when cost_myr is
 *     null (older rows), matching the writers' own convention.
 *
 *   - METRICOOL COST is a flat operator-set subscription: Metricool is one
 *     shared agency account on a fixed monthly plan, so its cost does NOT scale
 *     with signups. The figure is an assumption (and the UI says so).
 *
 *   - FIXED COST is operator-set (config/costs.php) and surfaced as such. The
 *     UI flags any 0 line so profit is never quietly overstated.
 *
 * Because revenue and AI cost both read live tables, the monitor moves the
 * instant a workspace signs up or an agent runs — which is the "live update
 * based on live signups" the brief asked for.
 */
class CostMonitor
{
    /**
     * Subscription states that mean "this workspace is paying us right now".
     * `active` = live paid subscription. We deliberately exclude `trialing`
     * (v1 has no trials, but be defensive) and `past_due` (money not yet
     * collected) so revenue reflects cash we can actually count on.
     */
    private const PAYING_STATES = ['active'];

    /** Plans that generate no revenue — internal EIAAW workspaces. */
    private const NON_REVENUE_PLANS = ['eiaaw_internal'];

    /**
     * Full monthly snapshot. Pass a month anchor (any date in the month);
     * defaults to the current month so the page is always "this month so far".
     *
     * @return array{
     *   period: array{label:string, start:Carbon, end:Carbon, is_current:bool, day_of_month:int, days_in_month:int},
     *   fx: float,
     *   signups: array{paying:int, by_plan:array<string,int>, internal:int},
     *   revenue: array{total_myr:float, by_plan:array<string,array{count:int,unit_myr:float,subtotal_myr:float,name:string}>},
     *   costs: array{ai_myr:float, ai_by_provider:array<string,float>, metricool_myr:float, railway:?array, fixed_myr:float, fixed_lines:array, total_myr:float},
     *   profit: array{net_myr:float, margin_pct:?float},
     *   projection: array{revenue_myr:float, ai_cost_myr:float, profit_myr:float}|null,
     *   warnings: list<string>
     * }
     */
    public function snapshot(?Carbon $month = null): array
    {
        $month = ($month ?? now())->copy();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $isCurrent = $start->isSameMonth(now());

        $fx = $this->fxRate();

        $signups = $this->signups();
        $revenue = $this->revenue($signups['by_plan']);
        $costs = $this->costs($start, $end, $fx);

        $netMyr = round($revenue['total_myr'] - $costs['total_myr'], 2);
        $marginPct = $revenue['total_myr'] > 0
            ? round(($netMyr / $revenue['total_myr']) * 100, 1)
            : null;

        return [
            'period' => [
                'label' => $start->format('F Y'),
                'start' => $start,
                'end' => $end,
                'is_current' => $isCurrent,
                'day_of_month' => $isCurrent ? now()->day : $end->day,
                'days_in_month' => $end->day,
            ],
            'fx' => $fx,
            'signups' => $signups,
            'revenue' => $revenue,
            'costs' => $costs,
            'profit' => [
                'net_myr' => $netMyr,
                'margin_pct' => $marginPct,
            ],
            'projection' => $isCurrent
                ? $this->projectMonthEnd($revenue['total_myr'], $costs, $start, $end)
                : null,
            'warnings' => $this->warnings($costs),
        ];
    }

    /**
     * USD→MYR rate from the cost register (defaults to the 4.7 used by the AI
     * writers). A null OR zero config value falls back to 4.7 — a 0 FX would
     * silently zero out every USD-denominated cost (Metricool + USD fixed lines),
     * understating cost and overstating profit, so we never let it through.
     */
    public function fxRate(): float
    {
        $rate = (float) config('costs.fx.usd_to_myr', 4.7);

        return $rate > 0 ? $rate : 4.7;
    }

    /**
     * Live signup counts. `by_plan` counts ONLY paying workspaces (drives
     * revenue). `internal` counts non-revenue EIAAW workspaces (excluded).
     *
     * @return array{paying:int, by_plan:array<string,int>, internal:int}
     */
    public function signups(): array
    {
        $byPlan = Workspace::query()
            ->whereIn('subscription_status', self::PAYING_STATES)
            ->whereNotIn('plan', self::NON_REVENUE_PLANS)
            ->selectRaw('plan, count(*) as c')
            ->groupBy('plan')
            ->pluck('c', 'plan')
            ->map(fn ($c) => (int) $c)
            ->all();

        $internal = Workspace::query()
            ->whereIn('plan', self::NON_REVENUE_PLANS)
            ->count();

        return [
            'paying' => array_sum($byPlan),
            'by_plan' => $byPlan,
            'internal' => $internal,
        ];
    }

    /**
     * Monthly recurring revenue from the live paying base. Each plan's price is
     * the REAL price from config/billing.php. (We use the catalog price, not
     * per-workspace Stripe amounts, because the catalog is the source of truth
     * for v1 flat pricing and existing subscribers on legacy prices are a known,
     * small set — a documented refinement if/when that base grows.)
     *
     * @param  array<string,int>  $countsByPlan  plan => live paying count
     */
    public function revenue(array $countsByPlan): array
    {
        $plans = (array) config('billing.plans', []);
        $byPlan = [];
        $total = 0.0;

        foreach ($countsByPlan as $plan => $count) {
            $unit = (float) ($plans[$plan]['price_myr'] ?? 0);
            $subtotal = $unit * $count;
            $total += $subtotal;
            $byPlan[$plan] = [
                'name' => (string) ($plans[$plan]['name'] ?? ucfirst($plan)),
                'count' => $count,
                'unit_myr' => $unit,
                'subtotal_myr' => round($subtotal, 2),
            ];
        }

        return [
            'total_myr' => round($total, 2),
            'by_plan' => $byPlan,
        ];
    }

    /**
     * Every cost line for the period, in MYR.
     *
     * @return array{ai_myr:float, ai_by_provider:array<string,float>, metricool_myr:float, fixed_myr:float, fixed_lines:array, total_myr:float}
     */
    public function costs(Carbon $start, Carbon $end, float $fx): array
    {
        $ai = $this->aiCost($start, $end, $fx);
        $metricoolMyr = $this->metricoolCost($fx);

        // Live Railway cost. When the API returns a figure we use it as a
        // MEASURED line and suppress the operator-set `railway` fixed line so
        // it is never double-counted. When it's null we leave the operator-set
        // line in place (fallback).
        $railway = $this->railwayCost($fx);
        $suppressFixed = $railway !== null ? ['railway'] : [];

        $fixed = $this->fixedCosts($fx, $suppressFixed);

        $railwayMyr = $railway['amount_myr'] ?? 0.0;
        $total = round($ai['total_myr'] + $metricoolMyr + $fixed['total_myr'] + $railwayMyr, 2);

        return [
            'ai_myr' => $ai['total_myr'],
            'ai_by_provider' => $ai['by_provider'],
            'metricool_myr' => $metricoolMyr,
            'railway' => $railway, // null when not wired / API down (operator-set line carries it)
            'fixed_myr' => $fixed['total_myr'],
            'fixed_lines' => $fixed['lines'],
            'total_myr' => $total,
        ];
    }

    /**
     * Live Railway infra cost (MEASURED) for this project, in MYR, or null when
     * the Railway API isn't wired or the call fails — in which case the
     * operator-set `fixed.railway` line carries the cost instead. Converts the
     * client's USD figure at FX and carries the USD estimate for the UI.
     *
     * @return array{amount_myr:float, current_usd:float, estimated_usd:float, estimated_myr:float, source:string}|null
     */
    public function railwayCost(float $fx): ?array
    {
        $cost = app(RailwayCostClient::class)->cost();

        if ($cost === null) {
            return null;
        }

        return [
            'amount_myr' => round($cost['current_usd'] * $fx, 2),
            'current_usd' => $cost['current_usd'],
            'estimated_usd' => $cost['estimated_usd'],
            'estimated_myr' => round($cost['estimated_usd'] * $fx, 2),
            'source' => $cost['source'],
        ];
    }

    /**
     * Real AI spend from the `ai_costs` ledger for the window. Uses stored
     * cost_myr; falls back to cost_usd × FX only where cost_myr is null. Done
     * in PHP (not one SQL COALESCE) so the fallback is explicit and testable,
     * and the row volume per month is small enough that this is cheap.
     *
     * @return array{total_myr:float, by_provider:array<string,float>}
     */
    public function aiCost(Carbon $start, Carbon $end, float $fx): array
    {
        $byProvider = [];
        $total = 0.0;

        AiCost::query()
            ->whereBetween('called_at', [$start, $end])
            ->select(['provider', 'cost_myr', 'cost_usd'])
            ->chunk(2000, function (Collection $rows) use (&$byProvider, &$total, $fx) {
                foreach ($rows as $row) {
                    $myr = $row->cost_myr !== null
                        ? (float) $row->cost_myr
                        : (float) $row->cost_usd * $fx;
                    $total += $myr;
                    $provider = $row->provider ?: 'unknown';
                    $byProvider[$provider] = ($byProvider[$provider] ?? 0.0) + $myr;
                }
            });

        $byProvider = array_map(fn ($v) => round($v, 2), $byProvider);
        arsort($byProvider);

        return [
            'total_myr' => round($total, 2),
            'by_provider' => $byProvider,
        ];
    }

    /** Metricool subscription = flat operator-set USD figure (one shared account), in MYR. */
    public function metricoolCost(float $fx): float
    {
        $amountUsd = (float) config('costs.subscriptions.metricool.amount_usd', 0);

        return round($amountUsd * $fx, 2);
    }

    /**
     * Fixed monthly infra from config/costs.php, each line normalised to MYR.
     * USD lines (`amount_usd`) convert at FX; MYR lines (`amount_myr`) pass
     * through. Returns per-line detail so the UI can flag 0/unset lines.
     *
     * @return array{total_myr:float, lines:list<array{key:string,label:string,amount_myr:float,source:string,is_zero:bool}>}
     */
    public function fixedCosts(float $fx, array $suppressKeys = []): array
    {
        $lines = [];
        $total = 0.0;

        foreach ((array) config('costs.fixed', []) as $key => $line) {
            // Skip any line now provided by a live measured source (e.g. the
            // operator-set `railway` line when the Railway API is wired).
            if (in_array((string) $key, $suppressKeys, true)) {
                continue;
            }

            if (array_key_exists('amount_usd', $line)) {
                $myr = round((float) $line['amount_usd'] * $fx, 2);
                $source = 'usd';
            } else {
                $myr = round((float) ($line['amount_myr'] ?? 0), 2);
                $source = 'myr';
            }

            $total += $myr;
            $lines[] = [
                'key' => (string) $key,
                'label' => (string) ($line['label'] ?? $key),
                'amount_myr' => $myr,
                'source' => $source,
                'is_zero' => $myr <= 0.0,
            ];
        }

        return [
            'total_myr' => round($total, 2),
            'lines' => $lines,
        ];
    }

    /**
     * Straight-line month-end projection FOR THE CURRENT MONTH. Revenue is
     * recurring so it does not pro-rate (the live MRR is already the month's
     * revenue). Only the variable AI cost is run-rated: (spend so far / days
     * elapsed) × days in month. Fixed + Metricool are recurring, so they carry
     * at face value. Clearly a projection, surfaced separately from actuals.
     */
    private function projectMonthEnd(float $revenueMyr, array $costs, Carbon $start, Carbon $end): array
    {
        $daysElapsed = max(1, now()->day);
        $daysInMonth = $end->day;

        $projectedAi = round(($costs['ai_myr'] / $daysElapsed) * $daysInMonth, 2);

        // Railway: prefer the API's own end-of-cycle ESTIMATE when measured
        // (Railway already projects it); otherwise the operator-set line is
        // already inside fixed_myr, so contribute 0 here.
        $projectedRailway = $costs['railway']['estimated_myr'] ?? 0.0;

        $projectedCostTotal = round(
            $projectedAi + $costs['metricool_myr'] + $costs['fixed_myr'] + $projectedRailway,
            2
        );

        return [
            'revenue_myr' => round($revenueMyr, 2),
            'ai_cost_myr' => $projectedAi,
            'profit_myr' => round($revenueMyr - $projectedCostTotal, 2),
        ];
    }

    /**
     * Honesty flags for the UI. We warn when fixed-cost lines are still 0
     * (profit is overstated until the operator enters real figures) and when
     * the Metricool subscription figure is unset.
     *
     * @return list<string>
     */
    private function warnings(array $costs): array
    {
        $warnings = [];

        $zeroLines = array_filter($costs['fixed_lines'], fn ($l) => $l['is_zero']);
        if (count($zeroLines) > 0) {
            $labels = implode(', ', array_map(fn ($l) => $l['label'], $zeroLines));
            $warnings[] = "Fixed cost not entered yet ({$labels}) — profit is overstated until set in config/costs.php.";
        }

        if ((float) config('costs.subscriptions.metricool.amount_usd', 0) <= 0) {
            $warnings[] = 'Metricool subscription is 0 — set COST_METRICOOL_MONTHLY_USD or config/costs.php.';
        }

        return $warnings;
    }
}
