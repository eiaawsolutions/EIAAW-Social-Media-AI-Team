<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-connection Blotato `target` overrides so business pages
 * (Facebook Page, LinkedIn Company, Pinterest board, etc.) can publish
 * via the same code path as personal accounts.
 *
 * Personal connections leave the column NULL — BlotatoClient sends no
 * pageId and Blotato routes to the personal profile.
 *
 * Business connections store the per-platform fields Blotato requires:
 *   linkedin (Company)  → {"pageId": "<linkedin company numeric id>"}
 *   facebook (Page)     → {"pageId": "<facebook page numeric id>"}
 *   pinterest           → {"boardId": "<pinterest board id>"}
 *   tiktok (Business)   → {"privacyLevel": "PUBLIC_TO_EVERYONE", ...}
 *   youtube (Channel)   → {"privacyStatus": "public", ...}
 *
 * The shape is platform-specific so we keep it as a free-form JSONB blob;
 * BlotatoClient::defaultTargetFor() merges it in at publish time.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->jsonb('target_overrides')->nullable()
                ->after('blotato_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropColumn('target_overrides');
        });
    }
};
