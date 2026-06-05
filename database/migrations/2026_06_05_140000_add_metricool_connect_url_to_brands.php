<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-brand Metricool "manage connections" link.
 *
 * Context: the customer connects their socials through a Metricool share-link
 * that HQ mints by hand ([[metricool-onboarding]]). That link was previously
 * EPHEMERAL — emailed once via brand:send-metricool-link and never stored — so
 * the wizard's "Manage connections" button had nowhere durable to send the
 * customer and instead pointed at the internal read-only platform-connections
 * table (which is meaningless to a customer: it's not where they manage the real
 * connection at the source). This column captures the share/manage link so the
 * button can deep-link the customer straight to Metricool to add/remove socials.
 *
 * Why a column and not derived: there is NO Metricool API to mint or fetch the
 * share-link, and the raw app.metricool.com dashboard can't be used (customers
 * have no login there, and it's ONE shared agency account — sending them there
 * would expose every other client's brands). So the only durable handle is the
 * per-brand share-link itself, stored here. It is NOT a secret (it's a tokenised
 * f.mtr.cool short link scoped to the one brand), so a plain column is fine.
 *
 * Lifecycle: brand:send-metricool-link now persists the URL here when it sends,
 * and brand:set-metricool-blog --connect-url can set/refresh it directly. If the
 * link expires (~71h on Metricool's side) the wizard falls back to a "request a
 * fresh link" flow rather than dead-ending the button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('metricool_connect_url', 2048)
                ->nullable()
                ->after('metricool_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('metricool_connect_url');
        });
    }
};
