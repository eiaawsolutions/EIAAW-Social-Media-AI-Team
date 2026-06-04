<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow plan='enterprise' on workspaces.
 *
 * The original create_workspaces migration defined plan as
 *   enum('solo','studio','agency','eiaaw_internal')
 * which Laravel renders on Postgres as a varchar + a CHECK constraint
 * (workspaces_plan_check). Enterprise is a "Talk to us" lead tier with no
 * self-serve checkout — but an operator must be able to provision a bespoke
 * workspace on plan='enterprise' after closing a deal, so the value must be a
 * legal plan. We widen the CHECK constraint to include it.
 *
 * Driver-aware: only Postgres carries the CHECK constraint. On sqlite (the test
 * DB) the column is plain TEXT with no constraint, so there's nothing to alter —
 * the value is already accepted. We guard on the driver to keep the test suite
 * green and avoid issuing Postgres-only DDL against sqlite.
 *
 * Mirrors the established pattern in
 * 2026_05_27_160000_add_queued_next_period_to_scheduled_posts.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // sqlite/other: no CHECK constraint to widen
        }

        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT IF EXISTS workspaces_plan_check');
        DB::statement(<<<'SQL'
            ALTER TABLE workspaces
            ADD CONSTRAINT workspaces_plan_check
            CHECK (plan IN ('solo', 'studio', 'agency', 'enterprise', 'eiaaw_internal'))
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Refuse to narrow the constraint if any workspace is currently on the
        // enterprise plan — dropping it to the old set would orphan that row.
        $stuck = DB::table('workspaces')->where('plan', 'enterprise')->count();
        if ($stuck > 0) {
            throw new \RuntimeException(
                "Cannot remove 'enterprise' from workspaces.plan — {$stuck} workspace(s) "
                . "still on that plan. Migrate them to another plan first."
            );
        }

        DB::statement('ALTER TABLE workspaces DROP CONSTRAINT IF EXISTS workspaces_plan_check');
        DB::statement(<<<'SQL'
            ALTER TABLE workspaces
            ADD CONSTRAINT workspaces_plan_check
            CHECK (plan IN ('solo', 'studio', 'agency', 'eiaaw_internal'))
        SQL);
    }
};
