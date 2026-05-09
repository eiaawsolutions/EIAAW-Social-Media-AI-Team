<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds competitor_intel_config (jsonb) to brands. Drives the weekly
 * CompetitorIntelAgent run.
 *
 * Shape:
 *   {
 *     "handles": [
 *       {"platform": "meta", "page_id": "123", "label": "Acme Corp"},
 *       {"platform": "linkedin", "company_slug": "acme-corp", "label": "Acme Corp"}
 *     ],
 *     "geo_codes": ["MY", "SG"],          // Meta Ad Library geo filter
 *     "last_refreshed_at": "2026-05-09T03:00:00Z",
 *     "last_run_id": 42,                  // pipeline_runs.id of latest scrape
 *     "enabled": true                      // operator can pause without losing handles
 *   }
 *
 * brand.competitors (existing column from BrandStyle/onboarding) keeps the
 * static "who we compete with" annotation. competitor_intel_config drives
 * the live fetch loop. Two separate columns because:
 *   - competitors is set once at onboarding, edited rarely
 *   - competitor_intel_config is mutated by the weekly cron + the operator
 *     when they tweak handles, and conflating the two would risk the cron
 *     overwriting onboarding evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('competitor_intel_config')->nullable()->after('competitors');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('competitor_intel_config');
        });
    }
};
