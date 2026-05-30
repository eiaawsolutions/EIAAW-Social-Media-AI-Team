<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the per-workspace Blotato account/onboarding columns — dead since the
 * Blotato decommission (Metricool is the sole publisher; account connection is
 * per-brand via brands.metricool_blog_id + the connect-link wizard).
 *
 * Only the 6 WORKSPACE onboarding columns are dropped here — after the
 * decommission their only readers (BlotatoClient::forWorkspace, the PlatformSetup
 * wizard, WorkspaceSetBlotatoHandle, PlatformSyncService, SetupReadiness's
 * blotato stage, Workspace::hasBlotatoConnected/blotatoSetupState, CostMonitor's
 * seat count) are all deleted or rewritten to Metricool.
 *
 * Deliberately NOT dropped (still have live model/column references; low-value
 * to churn now): platform_connections.blotato_account_id (Metricool sets null,
 * AuditAccounts + PlatformConnection model read it), post_metrics.blotato_*
 * (nullable, PostMetric model fillable/cast), scheduled_posts.blotato_post_id
 * (the GENERIC provider submission id — Metricool reuses it). Those can be
 * renamed/dropped in a later tidy-up if desired.
 *
 * Reversible: down() re-adds the columns (nullable), so a revert restores the
 * schema (the data is gone, but these were operational state, not records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            foreach ([
                'blotato_api_key_handle',
                'blotato_account_email',
                'blotato_connected_at',
                'blotato_setup_requested_at',
                'blotato_login_url',
                'blotato_credentials_sent_at',
            ] as $col) {
                if (Schema::hasColumn('workspaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('blotato_api_key_handle', 255)->nullable();
            $table->string('blotato_account_email', 255)->nullable();
            $table->timestamp('blotato_connected_at')->nullable();
            $table->timestamp('blotato_setup_requested_at')->nullable();
            $table->string('blotato_login_url', 500)->nullable();
            $table->timestamp('blotato_credentials_sent_at')->nullable();
        });
    }
};
