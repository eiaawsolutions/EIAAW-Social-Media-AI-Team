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

    protected $fillable = [
        'brand_id', 'workspace_id',
        'target_metric', 'platform',
        'target_value', 'baseline_value',
        'window_starts_on', 'window_ends_on',
        'status', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'integer',
            'baseline_value' => 'integer',
            'window_starts_on' => 'date',
            'window_ends_on' => 'date',
        ];
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
}
