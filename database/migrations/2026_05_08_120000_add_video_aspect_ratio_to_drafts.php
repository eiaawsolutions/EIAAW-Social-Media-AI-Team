<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-draft override for video aspect ratio. Null means "use VideoAgent's
     * platform default" (TikTok/IG/Threads/FB → 9:16, YouTube/LinkedIn/X → 16:9).
     * Allowed values: '9:16' | '16:9' | '1:1'. Stored as varchar so a future
     * format like '4:5' adds without a migration.
     */
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $t) {
            $t->string('video_aspect_ratio', 8)->nullable()->after('asset_urls');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $t) {
            $t->dropColumn('video_aspect_ratio');
        });
    }
};
