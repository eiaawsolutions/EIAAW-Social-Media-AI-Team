<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator-set growth goal for a brand (e.g. "grow Instagram followers to
 * 5,000 by Sep 2026"). GrowthStrategistAgent reads active goals to bias its
 * guidance; the dashboard shows progress. Progress is computed from REAL current
 * values — never fabricated.
 */
class BrandGrowthGoal extends Model
{
    public const METRICS = ['followers', 'reach', 'engagement_rate', 'link_clicks', 'profile_visits'];

    /**
     * How each metric accumulates — this determines how a "current" reading is
     * even defined, and it is why four of the five metrics used to be permanently
     * unmeasurable (see GrowthStrategistAgent::currentValueFor).
     *
     * STOCK  — a level you hold at a point in time (followers). Current = latest.
     * FLOW   — a quantity that accrues over the goal window (reach, clicks,
     *          visits). Current = sum since window_starts_on, and a baseline of 0
     *          is correct because we are counting accumulation from goal start.
     * RATIO  — an average, not a total (engagement_rate). Current = mean of the
     *          real readings in the window; summing it would be meaningless.
     */
    public const STOCK_METRICS = ['followers'];
    public const FLOW_METRICS = ['reach', 'link_clicks', 'profile_visits'];
    public const RATIO_METRICS = ['engagement_rate'];

    /** Feasibility verdicts, snapshotted at creation. */
    public const FEASIBILITY_PLAUSIBLE = 'plausible';
    public const FEASIBILITY_STRETCH = 'stretch';
    public const FEASIBILITY_INFEASIBLE = 'infeasible';
    public const FEASIBILITY_UNKNOWN = 'unknown';

    /** Required rate within this multiple of measured rate reads as plausible. */
    public const FEASIBILITY_PLAUSIBLE_MULTIPLE = 1.5;

    /** Beyond this multiple of measured rate the goal is arithmetically out of reach. */
    public const FEASIBILITY_STRETCH_MULTIPLE = 5.0;

    protected $fillable = [
        'brand_id', 'workspace_id',
        'target_metric', 'platform',
        'target_value', 'baseline_value',
        'window_starts_on', 'window_ends_on',
        'status', 'created_by_user_id',
        'lagging_streak', 'last_pace_status', 'last_progress_pct', 'last_evaluated_at',
        'required_per_day', 'observed_per_day', 'feasibility_verdict',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
            'baseline_value' => 'integer',
            'window_starts_on' => 'date',
            'window_ends_on' => 'date',
            'lagging_streak' => 'integer',
            'last_progress_pct' => 'float',
            'last_evaluated_at' => 'datetime',
            'required_per_day' => 'float',
            'observed_per_day' => 'float',
        ];
    }

    /** True when "current" means a level, not an accumulation. */
    public static function isStockMetric(string $metric): bool
    {
        return in_array($metric, self::STOCK_METRICS, true);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /**
     * Progress toward the goal as a percentage, given the REAL current value of
     * the target metric (caller supplies it from AccountGrowthService /
     * post_metrics — this model never fabricates a current reading).
     *
     * Measured against the snapshotted baseline so a goal of "5,000 followers"
     * set when the brand had 4,000 reads as 0% at 4,000 and 100% at 5,000 — not
     * 80% (which would credit the pre-existing base). Clamped to [0, 100].
     *
     * Returns null when the goal is degenerate (target ≤ baseline) so callers
     * can render "—" rather than a misleading number. Pure function — no I/O,
     * unit-testable DB-light.
     */
    public static function progressPct(int $baseline, int $target, ?int $current): ?float
    {
        if ($current === null) {
            return null; // no real reading available
        }
        $span = $target - $baseline;
        if ($span <= 0) {
            return null; // degenerate goal (target not above baseline)
        }
        $gained = $current - $baseline;
        $pct = $gained / $span * 100;

        return round(max(0.0, min(100.0, $pct)), 1);
    }

    /**
     * How far above/below the goal's "pace" the brand is, given progress toward
     * the target and where `$now` sits in the goal window.
     *
     * Pace is measured against LINEAR time-elapsed in the window: a goal at 30%
     * progress when 60% of its window has elapsed is behind and reads "lagging".
     * `expected_pct` = elapsed fraction of [windowStart, windowEnd] × 100, with
     * `$now` clamped into the window so we never report > 100% or < 0% expected.
     *
     *   - 'ahead'    — progress is more than TOLERANCE points above expected
     *   - 'on_track' — progress within ±TOLERANCE of expected
     *   - 'lagging'  — progress is more than TOLERANCE points below expected
     *   - null       — no real progress reading (progressPct null), a degenerate
     *                  window (end ≤ start), or before the window opens — callers
     *                  render "—" and the Strategist applies no pace pressure.
     *
     * Pure function: no I/O, no fabrication — `$progressPct` must already be a
     * real reading (or null). Unit-testable DB-light. Twin of progressPct().
     */
    public const PACE_TOLERANCE_PCT = 10.0;

    public static function paceStatus(
        ?float $progressPct,
        Carbon $windowStart,
        Carbon $windowEnd,
        Carbon $now,
    ): ?string {
        if ($progressPct === null) {
            return null; // no real reading — never invent a pace verdict
        }
        $spanSeconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
        if ($spanSeconds <= 0) {
            return null; // degenerate window
        }
        if ($now->lessThan($windowStart)) {
            return null; // window hasn't opened — no pace to judge yet
        }

        $elapsed = min($now->getTimestamp(), $windowEnd->getTimestamp()) - $windowStart->getTimestamp();
        $expectedPct = round($elapsed / $spanSeconds * 100, 1);

        $delta = $progressPct - $expectedPct;
        if ($delta > self::PACE_TOLERANCE_PCT) {
            return 'ahead';
        }
        if ($delta < -self::PACE_TOLERANCE_PCT) {
            return 'lagging';
        }

        return 'on_track';
    }

    /**
     * The expected progress percentage at `$now` — linear time-elapsed in the
     * window, clamped to [0, 100]. Returns null on a degenerate window or before
     * the window opens. Exposed so callers can SHOW the pace number ("60% of the
     * window elapsed") alongside the verdict from paceStatus(). Pure.
     */
    public static function expectedPct(Carbon $windowStart, Carbon $windowEnd, Carbon $now): ?float
    {
        $spanSeconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
        if ($spanSeconds <= 0 || $now->lessThan($windowStart)) {
            return null;
        }
        $elapsed = min($now->getTimestamp(), $windowEnd->getTimestamp()) - $windowStart->getTimestamp();

        return round($elapsed / $spanSeconds * 100, 1);
    }

    /**
     * Is this goal arithmetically reachable, given what the brand actually does?
     *
     * A goal of "1 Threads follower to 10,000 in 90 days" is not a plan; it is a
     * wish. Prod carried exactly that (goal#2, plus an Instagram twin at 7 →
     * 10,000) and the system's only response for two months was to report
     * "lagging" every week — technically true, entirely useless, and it made the
     * lagging signal worthless for the goals that WERE reachable.
     *
     * This compares the rate the goal demands against the rate the brand has
     * demonstrated:
     *   - plausible   — within 1.5x of measured pace
     *   - stretch     — within 5x; hard, but a real change in approach could do it
     *   - infeasible  — beyond 5x; no content plan closes this gap
     *   - unknown     — no measured rate to compare against (a new brand). We do
     *                   NOT guess; unknown is a real answer.
     *
     * Advisory only. The operator may set whatever target they like — this
     * labels it honestly instead of letting it masquerade as on-track-but-behind.
     *
     * Pure. `$observedPerDay` is supplied by the caller from real readings.
     *
     * @return array{required_per_day:?float,observed_per_day:?float,verdict:string,multiple:?float}
     */
    public static function feasibility(
        int $baseline,
        int $target,
        Carbon $windowStart,
        Carbon $windowEnd,
        ?float $observedPerDay,
    ): array {
        $days = $windowStart->diffInDays($windowEnd);
        $span = $target - $baseline;

        // Degenerate goal (target at or below baseline, or a zero-length window)
        // — progressPct already returns null for these; stay consistent.
        if ($days <= 0 || $span <= 0) {
            return [
                'required_per_day' => null,
                'observed_per_day' => $observedPerDay,
                'verdict' => self::FEASIBILITY_UNKNOWN,
                'multiple' => null,
            ];
        }

        $required = round($span / $days, 2);

        if ($observedPerDay === null) {
            return [
                'required_per_day' => $required,
                'observed_per_day' => null,
                'verdict' => self::FEASIBILITY_UNKNOWN,
                'multiple' => null,
            ];
        }

        // A brand producing nothing (or shrinking) cannot reach any positive
        // target by continuing as-is. Infeasible without a multiple to quote —
        // dividing by zero would be the only way to get a number here.
        if ($observedPerDay <= 0.0) {
            return [
                'required_per_day' => $required,
                'observed_per_day' => $observedPerDay,
                'verdict' => self::FEASIBILITY_INFEASIBLE,
                'multiple' => null,
            ];
        }

        $multiple = round($required / $observedPerDay, 2);

        $verdict = match (true) {
            $multiple <= self::FEASIBILITY_PLAUSIBLE_MULTIPLE => self::FEASIBILITY_PLAUSIBLE,
            $multiple <= self::FEASIBILITY_STRETCH_MULTIPLE => self::FEASIBILITY_STRETCH,
            default => self::FEASIBILITY_INFEASIBLE,
        };

        return [
            'required_per_day' => $required,
            'observed_per_day' => $observedPerDay,
            'verdict' => $verdict,
            'multiple' => $multiple,
        ];
    }

    /**
     * The next value of the lagging streak, given the newest verdict.
     *
     * Only a REAL 'lagging' reading advances the streak. A null verdict (no
     * reading, degenerate window, window not yet open) leaves the streak
     * untouched rather than resetting it — an unmeasurable week is not evidence
     * the goal recovered, and it is not evidence it got worse either. Any
     * non-lagging real verdict ('on_track' / 'ahead') resets to 0.
     *
     * Pure, so the escalation history can be unit-tested without a DB.
     */
    public static function nextStreak(int $currentStreak, ?string $paceStatus): int
    {
        $currentStreak = max(0, $currentStreak);

        return match ($paceStatus) {
            'lagging' => $currentStreak + 1,
            null => $currentStreak,
            default => 0,
        };
    }
}
