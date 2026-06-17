<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag support_enquiries rows by HOW they were captured:
 *   - 'enquiry'    — the existing "Talk to us" form (visitor chose to write us).
 *   - 'chat_gate'  — the contact gate shown before the AI assistant answers any
 *                    question (name + email + phone collected up front).
 *
 * Default 'enquiry' means zero backfill: every existing row and the untouched
 * contact() write path keep their meaning. Only the new identify() path stamps
 * 'chat_gate'. Indexed because the HQ Enquiries resource filters on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_enquiries', function (Blueprint $table) {
            $table->string('kind', 16)->default('enquiry')->after('surface')->index();
        });
    }

    public function down(): void
    {
        Schema::table('support_enquiries', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
