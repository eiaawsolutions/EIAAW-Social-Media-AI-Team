<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand Metricool connection bookkeeping for the onboarding wizard
 * (the Metricool replacement for the Blotato platform-setup handoff).
 *
 * The state machine is derived, not stored — but these two timestamps drive
 * the "link sent" and "connected" transitions:
 *   - metricool_connect_link_sent_at: when the operator/customer was given the
 *     Metricool share-link to connect their socials (71h expiry on Metricool's
 *     side). Distinguishes "mapped but no link yet" from "link sent, waiting".
 *   - metricool_connected_at: first time /admin/profile reported ≥1 connected
 *     network for this brand (set by MetricoolConnectionService / the wizard's
 *     detect step). Mirrors workspaces.blotato_connected_at.
 *
 * metricool_blog_id itself was added in 2026_05_30_160000; the mapping is the
 * first transition. Connection detection reads the live profile, so we don't
 * cache the network list here — only the connected-at fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->timestamp('metricool_connect_link_sent_at')->nullable()->after('metricool_blog_id');
            $table->timestamp('metricool_connected_at')->nullable()->after('metricool_connect_link_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['metricool_connect_link_sent_at', 'metricool_connected_at']);
        });
    }
};
