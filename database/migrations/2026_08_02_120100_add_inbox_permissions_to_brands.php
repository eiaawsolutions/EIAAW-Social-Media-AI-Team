<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the inbox permission preflight is recorded, so a permission problem is
 * VISIBLE instead of looking like silence.
 *
 * The failure this exists to prevent: a supported (provider, resource) pair
 * whose OAuth scopes were never granted returns HTTP 200 with an EMPTY list.
 * That is byte-identical to "nobody is talking to this brand". Live at time of
 * writing, on real accounts:
 *
 *   brand#1 + brand#14, post-comments/TIKTOKBUSINESS
 *       missingScopes: ["comment.list","comment.list.manage"]
 *   brand#14, conversations/INSTAGRAMBUSINESS
 *       missingScopes: ["instagram_business_manage_messages",
 *                       "instagram_business_manage_comments"]
 *       allowAccessToMessages: false
 *   brand#14, conversations/INSTAGRAM
 *       HTTP 400 "Cant found a page to verify permissions"
 *
 * Without this, the ingest fetches 200-empty, writes nothing, and the operator
 * reads "Nothing in the inbox yet" and concludes the client has no engagement.
 *
 * Shape: {"<resource>:<provider>": {"missing_scopes": [...],
 *         "allow_messages": bool, "error": "...", "checked_at": iso8601}}
 * Only entries WITH a problem are stored — a clean preflight writes nothing, so
 * a non-empty column always means "something needs an operator".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('inbox_permissions')->nullable()->after('metricool_blog_id');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('inbox_permissions');
        });
    }
};
