<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records HOW a scheduled_post's time was chosen, so the best-time learner can
 * tell a deliberate slot from an accident.
 *
 * Before this column the learner (GrowthStrategistAgent::computeBestPostingTimes)
 * read `published_at` for every post and treated the resulting hour as a signal
 * about the audience. It was mostly a signal about our own scheduler: on
 * 2026-08-01 prod brand#1 had 606 of 661 calendar entries with a NULL
 * scheduled_time, so nearly every post took either the hardcoded 09:00 fallback
 * (= 01:00 UTC, the largest single hour cluster) or, when the calendar slot had
 * already passed, `now() + offset` — which encodes LATENESS, not audience timing.
 *
 * Values:
 *   operator_pinned      — the calendar entry carried an explicit scheduled_time
 *   exploit              — chose the brief's computed best hour for (platform, dow)
 *   explore              — deliberately sampled a candidate hour (ε-greedy)
 *   default_fallback     — no brief, no exploration: the legacy 09:00
 *   past_slot_fallback   — the slot was already past; published at now()+offset
 *
 * Only `exploit` / `explore` / `operator_pinned` are admissible evidence about
 * timing. `past_slot_fallback` is EXCLUDED from the learner — see
 * GrowthStrategistAgent::computeBestPostingTimes.
 *
 * Nullable with no backfill: rows written before this migration have unknown
 * provenance and are treated as admissible-but-unlabelled (the learner only
 * excludes the explicitly-confounded value). Backfilling a guess would be
 * fabricating provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_posts', function (Blueprint $table) {
            $table->string('scheduling_strategy', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_posts', function (Blueprint $table) {
            $table->dropColumn('scheduling_strategy');
        });
    }
};
