<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two upload intents for a brand asset:
 *
 *   - general    (default, today's behaviour): the asset joins the pool the
 *                DesignerAgent + VideoAgent semantically pick from. The
 *                BrandAssetPicker matches it to auto-planned drafts.
 *
 *   - customised: the operator uploaded this asset to schedule ONE dedicated
 *                post around it (their own narrative or an AI-written one) on
 *                chosen platforms + date. It is RESERVED for that post and
 *                excluded from the general picker pool, so the agents never
 *                accidentally re-publish a hand-scheduled visual.
 *
 * The customised-intent bookkeeping columns record what the operator chose so
 * the asset row stays self-describing (and the table UI can show "scheduled
 * for IG+FB on Jun 3"). The actual publish runs through the normal
 * Draft → ScheduledPost → SubmitScheduledPost rail; these columns are
 * provenance, not a second scheduler.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('brand_assets', function (Blueprint $table): void {
            // general | customised. Existing rows are general (back-compat).
            $table->string('usage_intent', 20)->default('general')->after('source');

            // Customised-post provenance (null for general assets).
            $table->json('scheduled_platforms')->nullable()->after('usage_intent');
            $table->timestamp('scheduled_post_for')->nullable()->after('scheduled_platforms');
            // manual | ai_writer — how the narrative was produced.
            $table->string('narrative_source', 20)->nullable()->after('scheduled_post_for');
            // Link back to the calendar entry that drives the dedicated post(s),
            // so re-opening the asset can show its publish status.
            $table->foreignId('customised_calendar_entry_id')
                ->nullable()
                ->after('narrative_source')
                ->constrained('calendar_entries')
                ->nullOnDelete();
        });

        // The picker filters on (brand, media_type, usage_intent, archived).
        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->index(['brand_id', 'media_type', 'usage_intent', 'archived_at'], 'brand_assets_pick_idx');
        });
    }

    public function down(): void
    {
        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->dropIndex('brand_assets_pick_idx');
            $table->dropConstrainedForeignId('customised_calendar_entry_id');
            $table->dropColumn([
                'usage_intent',
                'scheduled_platforms',
                'scheduled_post_for',
                'narrative_source',
            ]);
        });
    }
};
