<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw, VERIFIED market & trend signals discovered via Firecrawl search.
 * Rolling window per brand. This table is the audit trail that proves the
 * truthfulness contract: every row carries a real source_url + fetched_at
 * (the MarketSignalNormalizer gate rejects anything without them, so
 * source_url is NOT nullable here by design). Synthesised into the
 * market_trend_briefs is_current row by MarketIntelAgent.
 *
 * Mirrors competitor_ads (the proven rolling-window + dedup_hash + expires_at
 * pattern). Separate from competitor_ads because this is the brand's MARKET /
 * TRENDS (third-party editorial/news), not competitor creatives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // market_news | industry_trend | seasonal_topical
            $table->string('signal_class', 32);
            $table->string('query');                 // the search query that surfaced it

            $table->string('title', 500);
            $table->text('snippet')->nullable();
            // NOT nullable — the verification gate guarantees a real evidence URL.
            $table->string('source_url', 1024);
            $table->timestamp('published_at')->nullable(); // article date when disclosed
            $table->timestamp('fetched_at');                // when we fetched + verified it

            $table->string('dedup_hash');            // sha1(canonical_url|title) for upsert
            $table->timestamp('observed_at');
            $table->timestamp('expires_at');         // rolling prune

            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();

            $table->timestamps();

            $table->index(['brand_id', 'observed_at']);
            $table->index(['expires_at']);
            $table->unique(['brand_id', 'dedup_hash'], 'market_signals_brand_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_signals');
    }
};
