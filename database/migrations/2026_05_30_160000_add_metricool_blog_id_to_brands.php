<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand Metricool blogId — the multi-tenancy primitive for the
 * Blotato→Metricool switch (see memory metricool-multitenancy).
 *
 * Unlike Blotato (one API account/key per workspace, stored as
 * workspaces.blotato_api_key_handle), Metricool is natively multi-brand:
 * ONE shared account + ONE token cover every brand, and each brand is
 * addressed by its numeric `blogId`. So the tenant key lives on the BRAND,
 * not the workspace, and it is NOT a secret — it's just an account-scoped id
 * (the token that pairs with it is the single shared Infisical handle in
 * config services.metricool.api_token).
 *
 * A brand with metricool_blog_id = null is simply not yet mapped to a
 * Metricool brand; the Metricool metrics collector returns no-data for it
 * and routing falls back to the existing providers (Meta Graph / CSV).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // Numeric Metricool blogId (their "brand" id). Stored as a string
            // to avoid any assumption about id width/format and to keep the
            // "unset = null" semantics clean. Indexed because the metrics
            // collector and the future audit:metricool-blogid-integrity
            // command look brands up by it.
            $table->string('metricool_blog_id', 64)->nullable()->after('logo_url');
            $table->index('metricool_blog_id');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['metricool_blog_id']);
            $table->dropColumn('metricool_blog_id');
        });
    }
};
