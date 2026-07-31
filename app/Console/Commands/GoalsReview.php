<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandGrowthGoal;
use App\Models\GrowthStrategyBrief;
use App\Services\Growth\GrowthPressure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The goal-lifecycle watchdog: says out loud what the growth loop is quietly
 * failing to do.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every failure mode below was live in prod on 2026-08-01 and none of them
 * surfaced anywhere:
 *
 *   - brand#14 held an active TikTok follower goal (4 -> 5,000) whose window
 *     closed THAT DAY. It had no growth brief at all — it never cleared the
 *     6-post minimum — so the goal had influenced nothing, ever, and was about
 *     to expire unmet in silence.
 *   - brand#1 held two `reach` goals that had been structurally unmeasurable
 *     since 2026-06-14: current_value null, progress null, pace null. They could
 *     never read 'lagging', so they never applied any pressure.
 *   - two follower goals demanded ~10,000 from bases of 7 and 1 in 90 days —
 *     arithmetically unreachable, reported weekly as merely "lagging".
 *
 * A system that escalates must also be able to say when escalation is pointless
 * or when it has run out of rungs. Reporting the gap is the alternative to
 * silently looking busy.
 *
 * Read-only. Safe to run any time; scheduled weekly ahead of intel:refresh.
 */
class GoalsReview extends Command
{
    protected $signature = 'goals:review
                            {--brand= : Restrict to a single brand id}
                            {--expiring-days=14 : Flag active goals whose window closes within this many days}
                            {--quiet-log : Do not write findings to the application log}';

    protected $description = 'Report growth goals that are expiring, unmeasurable, unreachable, or unactuatable.';

    public function handle(): int
    {
        $expiringDays = max(1, (int) $this->option('expiring-days'));

        $goals = BrandGrowthGoal::active()
            ->when($this->option('brand'), fn ($q, $id) => $q->where('brand_id', $id))
            ->orderBy('brand_id')
            ->get();

        if ($goals->isEmpty()) {
            $this->info('No active growth goals.');

            return self::SUCCESS;
        }

        $briefs = GrowthStrategyBrief::where('is_current', true)
            ->whereIn('brand_id', $goals->pluck('brand_id')->unique())
            ->get()
            ->keyBy('brand_id');

        $brands = Brand::whereIn('id', $goals->pluck('brand_id')->unique())->get()->keyBy('id');

        $findings = [
            'expiring' => [],
            'unmeasurable' => [],
            'unreachable' => [],
            'no_brief' => [],
            'unactuatable' => [],
        ];

        foreach ($goals as $goal) {
            $brand = $brands[$goal->brand_id] ?? null;
            $label = sprintf(
                'brand#%d %s — %s%s target %s by %s',
                $goal->brand_id,
                $brand?->name ?? '(unknown)',
                $goal->target_metric,
                $goal->platform ? " ({$goal->platform})" : ' (account-wide)',
                $goal->target_value,
                $goal->window_ends_on?->toDateString() ?? '(no end date)',
            );

            $brief = $briefs[$goal->brand_id] ?? null;
            $row = $brief ? $this->goalRow($brief, $goal) : null;

            // 1. No brief at all — the goal has never been evaluated.
            if (! $brief) {
                $findings['no_brief'][] = $label.' — brand has NO current growth brief, so this goal has never been evaluated';
            }

            // 2. Expiring soon.
            $daysLeft = $goal->window_ends_on
                ? (int) floor(now()->diffInDays($goal->window_ends_on->copy()->endOfDay(), false))
                : null;
            if ($daysLeft !== null && $daysLeft <= $expiringDays) {
                $progress = $row['progress_pct'] ?? $goal->last_progress_pct;
                $findings['expiring'][] = $label.sprintf(
                    ' — %s, at %s',
                    $daysLeft < 0 ? abs($daysLeft).' day(s) PAST its end date' : "closes in {$daysLeft} day(s)",
                    $progress !== null ? round((float) $progress).'% of target' : 'NO measurable progress',
                );
            }

            // 3. Structurally unmeasurable — evaluated, but no reading resolves.
            if ($row !== null && ($row['current_value'] ?? null) === null) {
                $findings['unmeasurable'][] = $label.' — evaluated but no current reading resolves; it can never read lagging and applies no pressure';
            }

            // 4. Arithmetically unreachable.
            if ($goal->feasibility_verdict === BrandGrowthGoal::FEASIBILITY_INFEASIBLE) {
                $findings['unreachable'][] = $label.sprintf(
                    ' — needs ~%s/day, brand measured at ~%s/day when the goal was set',
                    $goal->required_per_day !== null ? round((float) $goal->required_per_day, 2) : '?',
                    $goal->observed_per_day !== null ? round((float) $goal->observed_per_day, 2) : '?',
                );
            }

            // 5. Top rung reached but no actuator exists for it.
            if ($row !== null && (int) ($row['rung'] ?? 0) >= GrowthPressure::RUNG_DISTRIBUTION) {
                $findings['unactuatable'][] = $label.sprintf(
                    ' — pressure %.2f has reached %s, but no distribution actuator is implemented (CommunityAgent is a stub); the system cannot push harder than L3 on this goal',
                    (float) ($row['pressure'] ?? 0),
                    GrowthPressure::rungLabel(GrowthPressure::RUNG_DISTRIBUTION),
                );
            }
        }

        $this->render($findings, $goals->count());

        if (! $this->option('quiet-log')) {
            $total = array_sum(array_map('count', $findings));
            if ($total > 0) {
                Log::warning('goals:review found growth-goal issues', [
                    'active_goals' => $goals->count(),
                    'counts' => array_map('count', $findings),
                    'findings' => $findings,
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * The goal_progress row for this goal in the brand's current brief.
     * Matches on goal_id when present (rows written before that field existed
     * fall back to metric+platform, which is unique enough in practice).
     *
     * @return array<string,mixed>|null
     */
    private function goalRow(GrowthStrategyBrief $brief, BrandGrowthGoal $goal): ?array
    {
        foreach ((array) ($brief->goal_progress ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['goal_id'])) {
                if ((int) $row['goal_id'] === (int) $goal->id) {
                    return $row;
                }

                continue;
            }
            if (($row['target_metric'] ?? null) === $goal->target_metric
                && (($row['platform'] ?? null) ?: null) === ($goal->platform ?: null)) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,array<int,string>> $findings */
    private function render(array $findings, int $activeGoals): void
    {
        $headings = [
            'expiring' => 'Expiring / expired',
            'unmeasurable' => 'Unmeasurable (applies no pressure)',
            'unreachable' => 'Arithmetically unreachable',
            'no_brief' => 'Never evaluated (brand has no current brief)',
            'unactuatable' => 'Max pressure, no actuator left',
        ];

        $this->info("goals:review — {$activeGoals} active goal(s)");

        $total = 0;
        foreach ($headings as $key => $heading) {
            $rows = $findings[$key] ?? [];
            if ($rows === []) {
                continue;
            }
            $total += count($rows);
            $this->line('');
            $this->warn("{$heading} (".count($rows).')');
            foreach ($rows as $r) {
                $this->line("  - {$r}");
            }
        }

        $this->line('');
        $total === 0
            ? $this->info('No issues found.')
            : $this->warn("{$total} issue(s) found across {$activeGoals} active goal(s).");
    }
}
