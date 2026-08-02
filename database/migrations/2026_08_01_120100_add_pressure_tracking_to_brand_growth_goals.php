<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a growth goal from a static target into a tracked one.
 *
 * Before this migration, pace was recomputed from scratch on every weekly brief
 * and immediately discarded. That made escalation impossible: a goal lagging for
 * its 7th consecutive week was indistinguishable from one lagging for the first,
 * so the Strategist emitted byte-identical text either way (see
 * StrategistAgent::renderLaggingGoalsBlock).
 *
 * Columns:
 *   lagging_streak       — consecutive evaluations with pace_status='lagging'.
 *                          Reset to 0 on any non-lagging real reading. NOT
 *                          incremented when the reading is null (no reading is
 *                          not evidence of lagging — Truthfulness Contract).
 *   last_pace_status     — the previous verdict, so the streak can be advanced
 *                          idempotently and surfaced without a re-derivation.
 *   last_progress_pct    — the previous real progress reading (null when none).
 *   last_evaluated_at    — when the goal was last scored. Lets goals:review spot
 *                          goals that have never been evaluated at all.
 *   required_per_day     — (target - baseline) / days_in_window, snapshotted at
 *                          creation. The arithmetic floor the goal demands.
 *   observed_per_day     — the brand's MEASURED daily rate for the metric at
 *                          creation time, or null when unmeasurable.
 *   feasibility_verdict  — plausible | stretch | infeasible | unknown, from
 *                          BrandGrowthGoal::feasibility(). Snapshotted at
 *                          creation so a goal that was never arithmetically
 *                          reachable is labelled as such from day one instead of
 *                          silently reporting 'lagging' for its whole window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_growth_goals', function (Blueprint $table) {
            $table->unsignedInteger('lagging_streak')->default(0)->after('status');
            $table->string('last_pace_status', 16)->nullable()->after('lagging_streak');
            $table->decimal('last_progress_pct', 5, 1)->nullable()->after('last_pace_status');
            $table->timestamp('last_evaluated_at')->nullable()->after('last_progress_pct');
            $table->decimal('required_per_day', 12, 2)->nullable()->after('last_evaluated_at');
            $table->decimal('observed_per_day', 12, 2)->nullable()->after('required_per_day');
            $table->string('feasibility_verdict', 16)->nullable()->after('observed_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('brand_growth_goals', function (Blueprint $table) {
            $table->dropColumn([
                'lagging_streak',
                'last_pace_status',
                'last_progress_pct',
                'last_evaluated_at',
                'required_per_day',
                'observed_per_day',
                'feasibility_verdict',
            ]);
        });
    }
};
