<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Time-series of engagement readings per published post. We store
     * snapshots (not just last-known) so the Optimizer can see growth
     * curves: a post that earned 80% of its engagement in 24h is a
     * different signal than one that grew steadily over 30 days.
     *
     * Indexed by (scheduled_post_id, observed_at) for the typical
     * "metrics history for this post" query, and by (brand_id,
     * platform, observed_at) for the dashboard rollup.
     */
    public function up(): void
    {
        Schema::create('post_metrics', function (Blueprint $table): void {
            $table->id();

            // Anchor to the post we're measuring.
            $table->foreignId('scheduled_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // denormalised so the dashboard can filter without join

            // When the snapshot was taken (UTC).
            $table->timestamp('observed_at');

            // Source of truth for this snapshot.
            //   blotato_status — pulled from Blotato post-status response
            //   platform_api   — pulled directly from IG Graph / LI UGC / YT Data API
            //   csv_upload     — uploaded by operator (CSV exported from platform)
            //   webhook        — pushed by Meta/LI webhook (future)
            $table->string('source');

            // Core engagement counters. All nullable: not every platform
            // exposes every counter, and dashboards must show "—" not "0".
            $table->bigInteger('impressions')->nullable();
            $table->bigInteger('reach')->nullable();
            $table->bigInteger('likes')->nullable();
            $table->bigInteger('comments')->nullable();
            $table->bigInteger('shares')->nullable();
            $table->bigInteger('saves')->nullable();
            $table->bigInteger('video_views')->nullable();
            $table->bigInteger('profile_visits')->nullable();
            $table->bigInteger('url_clicks')->nullable();
            $table->decimal('engagement_rate', 8, 4)->nullable();

            // Raw provider blob for forensics + future fields we haven't
            // typed yet (e.g. saves vs collect on Xiaohongshu).
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['scheduled_post_id', 'observed_at']);
            $table->index(['brand_id', 'platform', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
