<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace Blotato isolation.
 *
 * Before this migration: every workspace shared a single global BLOTATO_API_KEY
 * (resolved from Infisical at eiaaw-smt-prod/prod/BLOTATO_API_KEY). Because
 * Blotato has no native multi-workspace concept and GET /v2/users/me/accounts
 * returns every social account connected to that one Blotato account, a
 * customer signing up would see HQ's TikTok/IG/etc. and — worse — a sync run
 * would upsert HQ's accounts into the customer's brand's platform_connections.
 *
 * After this migration: each workspace stores its own Infisical handle
 * (`secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_{id}`) referencing a
 * per-workspace Blotato account. BlotatoClient::forWorkspace() resolves it
 * on demand. Workspaces with no handle set are blocked from syncing and the
 * UI surfaces a "Connect your Blotato account" empty state instead.
 *
 * Per EIAAW Deploy Contract we store the HANDLE only — the raw API key
 * lives in Infisical and never enters the DB. Operator provisions the
 * Infisical secret manually (Claude does not call set_secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Infisical handle, e.g. "secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_42".
            // NOT the raw key. Resolved at request time by InfisicalResolver.
            $table->string('blotato_api_key_handle', 255)->nullable()->after('settings');

            // Bookkeeping: which Blotato account email this workspace owns,
            // so the operator can identify whose Blotato dashboard to log into
            // when rotating the key or investigating a sync failure.
            $table->string('blotato_account_email', 255)->nullable()->after('blotato_api_key_handle');

            // First successful ping() against the per-workspace key.
            // null = handle never validated (UI shows "needs connect").
            $table->timestamp('blotato_connected_at')->nullable()->after('blotato_account_email');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['blotato_api_key_handle', 'blotato_account_email', 'blotato_connected_at']);
        });
    }
};
