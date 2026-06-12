<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand growth-strategy config (mirrors market_intel_config). JSONB so the
 * shape can grow without migrations. v1 shape:
 *   { "enabled": true, "last_refreshed_at": "2026-..." }
 * Null = inherits the global default (on — all data is internal, no external
 * fetch). Set { "enabled": false } to opt a single brand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('growth_strategy_config')->nullable()->after('market_intel_config');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('growth_strategy_config');
        });
    }
};
