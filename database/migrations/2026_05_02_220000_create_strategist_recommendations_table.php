<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One row per Optimizer run per brand. Holds the synthesised pillar /
     * format / platform mix the Strategist should bias toward in its next
     * calendar build, plus the top 5 reference posts the recommendation
     * was derived from. Strategist reads the latest current=true row at
     * calendar-build time.
     */
    public function up(): void
    {
        Schema::create('strategist_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_current')->default(true);

            // Window the recommendation was computed against.
            $table->date('window_starts_on');
            $table->date('window_ends_on');

            // The recommended biases, all 0..1 weight maps. Empty arrays
            // mean "no data; use defaults from StrategistAgent."
            $table->json('pillar_mix');
            $table->json('format_mix');
            $table->json('platform_mix');

            // Top 5 (id, score, reason) for explainability + UI display.
            $table->json('top_performers');

            // Plain-English summary for the operator. The Optimizer agent
            // generates this so the operator can read "what's working".
            $table->text('summary')->nullable();

            $table->integer('post_count_in_window');
            $table->bigInteger('impressions_total')->default(0);
            $table->bigInteger('engagement_total')->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategist_recommendations');
    }
};
