<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
