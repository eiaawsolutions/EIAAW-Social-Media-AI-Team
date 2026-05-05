<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `branding_payload` (jsonb) to drafts. Stores the QuoteWriter
 * artefact (quote + voiceover) so Designer + Video share one Haiku call
 * per draft instead of distilling twice.
 *
 * Shape:
 *   { "quote": "...", "voiceover": "...", "distilled_at": "ISO 8601" }
 *
 * Why JSON-on-the-row instead of a separate table: the artefact is 1:1
 * with the draft, never queried independently, never modified after
 * first distil, and we already use this pattern (asset_urls). A separate
 * table would add a join with no win.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->json('branding_payload')->nullable()->after('asset_urls');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('branding_payload');
        });
    }
};
