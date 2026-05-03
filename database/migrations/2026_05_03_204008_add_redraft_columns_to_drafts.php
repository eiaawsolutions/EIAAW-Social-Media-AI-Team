<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the bookkeeping columns the auto-redraft loop needs.
 *
 * revision_count: how many times the Writer has rewritten this draft trying
 * to clear Compliance. Capped in the redraft job so we don't burn LLM
 * budget on a draft the model can't fix (e.g. a banned-phrase fail where
 * the topic itself is the violation).
 *
 * last_redraft_at: gives the cron a cheap "don't re-pick a draft we just
 * tried" filter — and shows up in the UI so the operator sees the loop is
 * working without tailing a queue dashboard.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->unsignedTinyInteger('revision_count')->default(0)->after('rejection_reason');
            $table->timestamp('last_redraft_at')->nullable()->after('revision_count');

            // Index helps the cron query that pulls compliance_failed rows
            // under the cap and not recently retried.
            $table->index(['status', 'revision_count', 'last_redraft_at'], 'drafts_redraft_loop_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_redraft_loop_idx');
            $table->dropColumn(['revision_count', 'last_redraft_at']);
        });
    }
};
