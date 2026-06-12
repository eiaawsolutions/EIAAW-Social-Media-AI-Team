<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One CURRENT row per brand. The strategic READ synthesised from the raw
 * competitor_ads we already collect weekly — competitors' messaging pillars,
 * positioning, share-of-voice, and the WHITESPACE no competitor is addressing.
 *
 * Why a separate table (mirrors strategist_recommendations, NOT a column on
 * brands and NOT folded into competitor_ads):
 *   - competitor_ads is raw observed creatives (rolling 30d, many rows/brand);
 *     this is ONE synthesised artifact with provenance the Strategist reads
 *     once, cheaply, via is_current=true — the exact StrategistRecommendation
 *     access pattern.
 *   - It must survive a week where the ad fetch returns nothing — last good
 *     synthesis stays injected.
 *
 * Populated weekly by CompetitorStrategistAgent (inside intel:refresh, right
 * after the raw ad pull); consumed by StrategistAgent on the next calendar
 * build. share_of_voice is recomputed in PHP from real ad counts — never
 * trusted from the model — so the headline number is always evidence-true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_strategy_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->boolean('is_current')->default(true);

            // Window of competitor_ads the synthesis was computed against.
            $table->date('window_starts_on');
            $table->date('window_ends_on');

            // [{theme, competitors:[label,...], frequency}] — patterns visible
            // across ≥2 ads (the prompt drops anything thinner).
            $table->json('dominant_themes')->nullable();
            // [{competitor_label, positioning_summary, primary_pillars:[...]}]
            $table->json('positioning_map')->nullable();
            // {competitor_label: pct} — recomputed in PHP from ad counts, not
            // echoed from the model.
            $table->json('share_of_voice')->nullable();
            // [theme,...] — themes NO competitor is addressing = the brand's opening.
            $table->json('whitespace')->nullable();

            // Observed posting/refresh rhythm, plain English.
            $table->text('cadence_notes')->nullable();
            // Operator-facing plain-English read.
            $table->text('summary')->nullable();

            // Provenance / verification floor.
            $table->integer('source_ad_count')->default(0);
            $table->string('model_id')->nullable();
            $table->string('prompt_version')->nullable();
            $table->decimal('cost_usd', 10, 5)->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_strategy_briefs');
    }
};
