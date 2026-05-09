<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the pillar/repurpose plumbing.
 *
 * 1. calendar_entries.is_pillar (bool, default false)
 *    Operator marks an entry as a pillar piece. When true, DraftCalendarEntry
 *    drafts a single MASTER draft (on the primary platform) and then runs
 *    RepurposeAgent to fan out platform-specific derivative drafts. When
 *    false (default), every platform gets an independent fresh draft —
 *    the v1 behaviour, unchanged.
 *
 * 2. drafts.parent_draft_id (FK, nullable)
 *    Derivative drafts point at the master they were repurposed from. Null
 *    on master drafts and on independent drafts (the default flow).
 *
 *    Allows /agency/drafts to group "1 master + 4 derivatives" visually so
 *    the operator sees the family at a glance, and lets the master's
 *    rejection cascade-cancel pending derivatives.
 *
 * Why nullable + additive: zero migration risk for existing in-flight
 * calendars and drafts. Only entries the operator explicitly toggles take
 * the new path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_entries', function (Blueprint $table) {
            $table->boolean('is_pillar')->default(false)->after('research_brief');
            $table->index(['brand_id', 'is_pillar']);
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->foreignId('parent_draft_id')
                ->nullable()
                ->after('calendar_entry_id')
                ->constrained('drafts')
                ->nullOnDelete();
            $table->index(['parent_draft_id']);
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['parent_draft_id']);
            $table->dropIndex(['parent_draft_id']);
            $table->dropColumn('parent_draft_id');
        });

        Schema::table('calendar_entries', function (Blueprint $table) {
            $table->dropIndex(['brand_id', 'is_pillar']);
            $table->dropColumn('is_pillar');
        });
    }
};
