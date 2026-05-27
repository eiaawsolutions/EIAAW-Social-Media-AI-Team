<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `queued_next_period` to scheduled_posts.status enum.
 *
 * When a workspace hits its monthly published-posts cap, SubmitScheduledPost
 * flips the row to this status with `queued_for_period_at` = first of next
 * month (workspace TZ). The scheduled `posts:release-queued-next-period`
 * command runs hourly and flips them back to `queued` on/after that timestamp
 * so the regular dispatcher picks them up.
 *
 * Postgres requires `ALTER TYPE … ADD VALUE` for enum changes — Laravel's
 * Blueprint::enum doesn't help here because changing an enum column is
 * destructive on Postgres. We add the value to the underlying type directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find the enum type Postgres created when the original enum column
        // was defined (varies by Laravel version — usually
        // {table}_{column}_check is a CHECK constraint and the actual
        // enum is anonymous, so we walk pg_constraint to find it).
        //
        // Simpler approach: drop+recreate the CHECK constraint that
        // Laravel's enum maps to. Verified against Postgres 16 behaviour
        // observed in the original create_content_tables migration.
        DB::statement('ALTER TABLE scheduled_posts DROP CONSTRAINT IF EXISTS scheduled_posts_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE scheduled_posts
            ADD CONSTRAINT scheduled_posts_status_check
            CHECK (status IN ('queued', 'submitting', 'submitted', 'published', 'failed', 'cancelled', 'queued_next_period'))
        SQL);

        Schema::table('scheduled_posts', function (Blueprint $table) {
            // The wall-clock time at which the release command should pull
            // this row back to status='queued' (= first of next month at
            // 00:05 workspace TZ, stored as UTC). Indexed because the
            // release command scans for `status='queued_next_period' AND
            // queued_for_period_at <= now()` every hour.
            $table->timestamp('queued_for_period_at')->nullable()->after('submitted_at');
            $table->index(['status', 'queued_for_period_at']);
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'queued_for_period_at']);
            $table->dropColumn('queued_for_period_at');
        });

        // Restore original CHECK constraint. Note: if any rows are currently
        // in status='queued_next_period' this will fail — operator must
        // first reset them. Add a guard.
        $stuck = DB::table('scheduled_posts')->where('status', 'queued_next_period')->count();
        if ($stuck > 0) {
            throw new \RuntimeException(
                "Cannot drop queued_next_period status — {$stuck} row(s) still in that state. "
                . "Run: UPDATE scheduled_posts SET status='queued' WHERE status='queued_next_period' first."
            );
        }

        DB::statement('ALTER TABLE scheduled_posts DROP CONSTRAINT IF EXISTS scheduled_posts_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE scheduled_posts
            ADD CONSTRAINT scheduled_posts_status_check
            CHECK (status IN ('queued', 'submitting', 'submitted', 'published', 'failed', 'cancelled'))
        SQL);
    }
};
