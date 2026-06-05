<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise deals are invoice-based, not Stripe-checkout: an operator agrees
 * specs/caps/price with the customer, issues a one-off Stripe Invoice, and the
 * workspace activates when that invoice is paid. These columns carry the whole
 * deal on the enquiry record so HQ has one row from lead → agreed terms →
 * invoice → provisioned workspace.
 *
 *  - agreed_* : the negotiated caps + monthly price (operator-entered at
 *    provision time). agreed_price_myr is the bespoke monthly figure; it is also
 *    snapshotted onto the workspace for later MRR (not yet wired to CostMonitor).
 *  - stripe_invoice_id / stripe_invoice_url : the one-off Stripe Invoice + its
 *    hosted pay link. NO subscription is created for Enterprise.
 *  - invoice_status : draft | sent | paid | void — drives the HQ badge + tells
 *    the webhook which enquiry an invoice.payment_succeeded belongs to.
 *  - provisioned_workspace_id : the bespoke workspace created for this deal.
 *
 * All nullable: a fresh lead has none of these; they fill in as the deal moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_enquiries', function (Blueprint $table) {
            // Agreed (negotiated) specification — operator enters at provision.
            $table->unsignedInteger('agreed_brands')->nullable()->after('budget_band');
            $table->unsignedInteger('agreed_image_posts')->nullable()->after('agreed_brands');
            $table->unsignedInteger('agreed_video_posts')->nullable()->after('agreed_image_posts');
            // Bespoke monthly price in MYR (whole ringgit, matching billing config).
            $table->unsignedInteger('agreed_price_myr')->nullable()->after('agreed_video_posts');

            // One-off Stripe Invoice (no subscription for Enterprise).
            $table->string('stripe_invoice_id')->nullable()->after('agreed_price_myr')->index();
            $table->string('stripe_invoice_url', 500)->nullable()->after('stripe_invoice_id');
            // draft | sent | paid | void. Null = not yet invoiced.
            $table->string('invoice_status', 16)->nullable()->after('stripe_invoice_url')->index();
            $table->timestamp('invoice_paid_at')->nullable()->after('invoice_status');

            // The bespoke workspace provisioned for this deal (set at provision).
            $table->foreignId('provisioned_workspace_id')->nullable()->after('invoice_paid_at')
                ->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_enquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provisioned_workspace_id');
            $table->dropColumn([
                'agreed_brands', 'agreed_image_posts', 'agreed_video_posts', 'agreed_price_myr',
                'stripe_invoice_id', 'stripe_invoice_url', 'invoice_status', 'invoice_paid_at',
            ]);
        });
    }
};
