<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand market-intel config (mirrors competitor_intel_config). JSONB so we
 * can grow the shape without migrations. v1 shape:
 *   {
 *     "enabled": true,                 // per-brand opt-in (global flag also gates)
 *     "extra_queries": ["..."],        // optional operator-supplied search seeds
 *     "last_refreshed_at": "2026-..."  // set by MarketIntelAgent
 *   }
 * Null = inherits the global default (off until MARKET_INTEL_ENABLED=true, then
 * enabled per brand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('market_intel_config')->nullable()->after('audience_profile');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('market_intel_config');
        });
    }
};
