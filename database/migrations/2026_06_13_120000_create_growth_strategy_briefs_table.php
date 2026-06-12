<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One is_current row per brand: the synthesised GROWTH strategy brief. Unlike
 * StrategistRecommendation (which learns the pillar/format/platform MIX), this
 * holds a DISJOINT signal set computed from the brand's OWN real performance —
 * posting time, hook-pattern win-rates, CTA/conversion lift, reach/platform
 * focus, follower-growth velocity, and per-objective steering guidance.
 *
 * Every numeric column is computed deterministically in PHP from real data
 * (post_metrics / ScheduledPost.published_at / AccountGrowthService) — the LLM
 * only narrates them (rationale/summary) and produces objective_guidance.
 *
 * Mirrors competitor_strategy_briefs / market_trend_briefs (the established
 * weekly-synthesis-brief pattern). Written by GrowthStrategistAgent inside the
 * weekly intel:refresh beat; read by StrategistAgent (calendar-build), WriterAgent
 * (per-objective guidance), the auto-scheduler (best times), and the dashboard card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_strategy_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->boolean('is_current')->default(true);

            $table->date('window_starts_on');
            $table->date('window_ends_on');

            // ── COMPUTED SIGNALS (PHP, never model-invented) ────────────────
            // {platform: [{day_of_week, hour, avg_score, sample_n}], ...}
            $table->json('best_posting_times')->nullable();
            // {platform: {reach_share_pct, impressions_share_pct, sample_n}}
            $table->json('platform_focus')->nullable();
            // [{hook_pattern, avg_engagement, win_rate, sample_n}] sorted desc
            $table->json('hook_performance')->nullable();
            // {with_cta:{avg_url_clicks,avg_profile_visits,n}, without_cta:{...}, lift_pct, has_signal}
            $table->json('cta_lift')->nullable();
            // {network: {net_new, direction, latest, sample_days}}
            $table->json('follower_velocity')->nullable();
            // {awareness:pct, engagement:pct, traffic:pct, leads:pct, retention:pct}
            $table->json('recommended_objective_mix')->nullable();
            // Active operator goals + computed progress, for the dashboard card.
            $table->json('goal_progress')->nullable();

            // ── LLM-STRUCTURED (the only model-populated structure) ─────────
            // {objective: {hook_patterns:[enum], cta_styles:[string]}}
            $table->json('objective_guidance')->nullable();

            // ── NARRATIVE (LLM) ─────────────────────────────────────────────
            $table->text('rationale')->nullable();  // ties each rec to a computed signal
            $table->text('summary')->nullable();    // operator-facing 2–3 sentences

            // ── PROVENANCE / VERIFICATION FLOOR ─────────────────────────────
            $table->integer('post_count_in_window')->default(0);
            $table->string('model_id')->nullable();
            $table->string('prompt_version')->nullable();
            $table->decimal('cost_usd', 10, 5)->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_strategy_briefs');
    }
};
