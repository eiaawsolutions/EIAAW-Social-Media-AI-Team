<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a `stripe_id` column to workspaces that mirrors `stripe_customer_id`.
 *
 * Why: Cashier 16's Cashier::findBillable() and SubscriptionBuilder::createSubscription()
 * issue raw queries like `where("stripe_id", $stripeId)` against the billable model.
 * Our column is named `stripe_customer_id` (kept for clarity vs users.stripe_id and
 * parity with the rest of the EIAAW stack). Cashier's accessor/mutator proxy on the
 * Workspace model handles attribute reads/writes on a hydrated instance, but does
 * NOT translate raw query-builder column names — so Cashier's query throws
 * "column stripe_id does not exist".
 *
 * Fix: add `stripe_id` as a stored generated column that always equals
 * `stripe_customer_id`. Postgres keeps them in sync automatically; existing app
 * code keeps using `stripe_customer_id`; Cashier's queries see `stripe_id` and
 * just work.
 *
 * The accessor/mutator on the Workspace model (services Cashier's get/set
 * via $workspace->stripe_id) is now redundant for reads but harmless — and we
 * keep it because Cashier may write `stripe_id` directly via mass assignment
 * during customer creation, which would fail against a generated column.
 *
 * Discovered when reconciling cs_live_b1lX73Rq29uHw... — the
 * customer.subscription.created webhook arrived, Cashier called findBillable()
 * with the customer ID, the SQL threw, and the event landed with err=YES in
 * subscription_events. After this migration the webhook re-processes cleanly
 * (idempotency-protected by stripe_event_id unique constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the column as a generated, always-stored alias of stripe_customer_id.
        // Postgres maintains this on every INSERT/UPDATE — no app code changes.
        DB::statement(<<<'SQL'
            ALTER TABLE workspaces
            ADD COLUMN stripe_id VARCHAR(255)
            GENERATED ALWAYS AS (stripe_customer_id) STORED
        SQL);

        // Index for Cashier's lookup query (where stripe_id = ...).
        Schema::table('workspaces', function ($table) {
            $table->index('stripe_id', 'workspaces_stripe_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function ($table) {
            $table->dropIndex('workspaces_stripe_id_index');
        });
        DB::statement('ALTER TABLE workspaces DROP COLUMN stripe_id');
    }
};
