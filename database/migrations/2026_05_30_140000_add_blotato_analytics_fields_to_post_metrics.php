<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Two fields for the Blotato analytics collector (added 2026-05-30):
     *
     *   blotato_published_id — the platform-side publishedPostId resolved from
     *     GET /v2/published-posts (joined on postUrl == platform_post_url).
     *     Distinct from scheduled_posts.blotato_post_id, which is the SUBMISSION
     *     id. Cached on the snapshot so we don't re-resolve the list every run.
     *
     *   blotato_last_fetched_at — Blotato's own `lastFetchedAt` for the snapshot.
     *     Blotato serves cached metrics and refreshes on its cadence, not on
     *     our request; this lets the dashboard show data freshness and lets the
     *     collector skip writing a duplicate snapshot when Blotato hasn't
     *     refreshed since our last reading.
     *
     * Both nullable — the source='blotato_status' history rows and csv_upload
     * rows don't carry them, and the analytics backend is unshipped (every
     * post 404s today), so the dormant collector writes no-data snapshots with
     * these null until Blotato turns analytics on.
     */
    public function up(): void
    {
        Schema::table('post_metrics', function (Blueprint $table): void {
            $table->string('blotato_published_id')->nullable()->after('source');
            $table->timestamp('blotato_last_fetched_at')->nullable()->after('blotato_published_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_metrics', function (Blueprint $table): void {
            $table->dropColumn(['blotato_published_id', 'blotato_last_fetched_at']);
        });
    }
};
