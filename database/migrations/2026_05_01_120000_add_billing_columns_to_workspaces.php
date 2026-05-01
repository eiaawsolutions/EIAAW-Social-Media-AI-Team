<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces are the Cashier billable model (flat brand-based pricing —
 * not per-user). The columns added here mirror the employee-portal pattern:
 *
 *   subscription_status — high-level state: trialing | active | past_due
 *                         | canceled | none. Drives the trial-expiry guard.
 *   past_due_at         — set on first invoice.payment_failed; the
 *                         scheduled grace-period suspension reads this.
 *   canceled_at         — set on customer.subscription.deleted; 30-day
 *                         read-only grace before hard delete.
 *   pm_type / pm_last_four — Cashier requires these on the billable model
 *                            for default-payment-method UI (last4 of card).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('subscription_status', 32)
                ->default('trialing')
                ->after('plan');
            $table->timestamp('past_due_at')->nullable()->after('subscription_status');
            $table->timestamp('canceled_at')->nullable()->after('past_due_at');
            $table->string('pm_type', 32)->nullable()->after('stripe_customer_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');

            $table->index('subscription_status');
        });

        // Backfill: any workspace already created before this migration is
        // assumed to be on a fresh trial (the alternative — flagging them
        // as 'none' — would lock out internal accounts on next deploy).
        DB::table('workspaces')
            ->whereNull('trial_ends_at')
            ->update([
                'subscription_status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);

        // EIAAW internal workspaces never expire.
        DB::table('workspaces')
            ->where('plan', 'eiaaw_internal')
            ->update([
                'subscription_status' => 'active',
                'trial_ends_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['subscription_status']);
            $table->dropColumn([
                'subscription_status',
                'past_due_at',
                'canceled_at',
                'pm_type',
                'pm_last_four',
            ]);
        });
    }
};
