<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores observed competitor ad creatives. Rolling 30-day window per brand.
 * Populated weekly by CompetitorIntelAgent; consumed by StrategistAgent on
 * the next calendar build.
 *
 * Storage strategy: persist the snapshot, not the moving target. If Meta
 * later removes an ad, our row stays — the Strategist's reasoning trail
 * survives. Cleanup is via expires_at (default = ingest + 30 days).
 *
 * Why a separate table instead of folding into brand_corpus:
 *   - brand_corpus is the BRAND's own historical posts; competitor ads
 *     are the OPPOSITE — what we're learning from, not from us.
 *   - Different lifecycle (corpus = forever, intel = rolling 30d).
 *   - Different retrieval (Strategist needs "top recent themes per
 *     competitor", not nearest-neighbor on brand voice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // Source identity
            $table->enum('platform', ['meta', 'linkedin']);
            $table->string('competitor_handle');        // page_id (meta) or company_slug (linkedin)
            $table->string('competitor_label')->nullable(); // human name e.g. "Acme Corp"

            // Ad identity (provider id when present; else hashed-url for dedup)
            $table->string('source_ad_id')->nullable(); // Meta Ad Library ad_archive_id / LI ad-spec id
            $table->string('source_url')->nullable();   // permalink to the ad
            $table->string('dedup_hash');               // sha1(platform|competitor_handle|body|asset_url) for upsert

            // Ad content
            $table->text('body')->nullable();           // creative copy
            $table->json('asset_urls')->nullable();     // image/video URLs (scraped, not rehosted)
            $table->string('cta')->nullable();          // "Learn more" / "Sign up" etc.
            $table->string('landing_url')->nullable();
            $table->json('targeting')->nullable();      // {countries, demographics} when available
            $table->json('platforms_seen_on')->nullable(); // ['facebook', 'instagram', 'audience_network']

            // Timing
            $table->timestamp('first_seen_at')->nullable(); // ad_creation_time / disclosed start
            $table->timestamp('last_seen_at')->nullable();  // last time scrape confirmed it was live
            $table->timestamp('observed_at');               // when we recorded it
            $table->timestamp('expires_at');                // observed_at + 30d, used for cleanup

            // Provenance
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();

            $table->timestamps();

            // Hot path: Strategist asks "what did this brand's competitors
            // run in the last 30 days, ranked by recency". Index supports
            // it directly.
            $table->index(['brand_id', 'observed_at']);
            $table->index(['workspace_id', 'observed_at']);
            $table->unique(['brand_id', 'platform', 'dedup_hash'], 'competitor_ads_brand_dedup_unique');
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_ads');
    }
};
