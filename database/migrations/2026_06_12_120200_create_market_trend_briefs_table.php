<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One is_current row per brand: the synthesised market & trend brief the
 * Strategist reads at calendar-build. Synthesised by MarketIntelAgent over
 * ONLY the verified market_signals; every trend cites ≥1 signal id (the
 * post-synthesis evidence-id filter drops uncited trends). verified_signal_count
 * is the provenance floor — a brief with 0 surviving signals is not written, so
 * the Strategist block self-suppresses rather than injecting hollow context.
 *
 * Mirrors strategist_recommendations / competitor_strategy_briefs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_trend_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->boolean('is_current')->default(true);

            $table->date('window_starts_on');
            $table->date('window_ends_on');

            $table->text('market_summary')->nullable();
            // [{trend, evidence_signal_ids:[...], why_relevant, suggested_angle}]
            $table->json('trends')->nullable();
            // [{moment, window, why_relevant}] — upcoming seasonal/topical hooks
            $table->json('seasonal_moments')->nullable();

            $table->integer('verified_signal_count')->default(0);
            $table->text('summary')->nullable();

            $table->string('model_id')->nullable();
            $table->string('prompt_version')->nullable();
            $table->decimal('cost_usd', 10, 5)->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_trend_briefs');
    }
};
