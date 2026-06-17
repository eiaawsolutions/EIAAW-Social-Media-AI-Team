<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * legal_acceptances — append-only audit log of every time a user affirmatively
 * accepted our legal documents (Terms / Acceptable Use / AI Disclaimer /
 * Privacy / DPA).
 *
 * WHY A TABLE *AND* TWO USER COLUMNS
 * ----------------------------------
 * The table is the legally load-bearing record: one row PER acceptance event,
 * never updated. Re-acceptance after a version bump is a NEW row, so the full
 * history (what version, when, from which IP/agent, via which surface) is
 * preserved for evidence if a customer ever disputes the terms.
 *
 * The two denormalized columns on `users` (legal_accepted_version,
 * legal_accepted_at) are a cache so the gate middleware
 * (EnforceLegalAcceptance) can decide "has this user accepted the CURRENT
 * version?" with a single column compare per authenticated request — no join,
 * no subquery. This mirrors how EnforceTrialOrSubscription reads the
 * denormalized workspaces.subscription_status.
 *
 * `document_version` is a DATED string (e.g. "2026-06-17"), not an int — it
 * doubles as the human-readable version shown on each legal page and matches
 * the existing "Last updated <date>" idiom in the legal blades.
 *
 * NOTE: unrelated to compliance_legal_rules — that table is the curated
 * rulebook the agents reason over for POST CONTENT legality. This is
 * USER-FACING terms acceptance.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The legal version the user accepted, e.g. "2026-06-17". Compared
            // against config('legal.version') by the gate.
            $table->string('document_version', 40);

            $table->timestamp('accepted_at');

            // Nullable: the webhook safety-net / queue provisioning path has no
            // HTTP request, so no IP / user-agent is available there.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Snapshot of the document manifest (per-doc name + updated date)
            // accepted in this event, for a self-contained audit record.
            $table->json('documents_json')->nullable();

            // Which surface captured the acceptance: 'signup' (Stripe checkout),
            // 'panel' (the in-app acceptance wall), or 'register' (the dormant
            // Filament direct-registration fallback).
            $table->string('source', 32)->default('panel');

            $table->timestamps();

            $table->index(['user_id', 'document_version']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('legal_accepted_version', 40)->nullable()->after('avatar_url');
            $table->timestamp('legal_accepted_at')->nullable()->after('legal_accepted_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['legal_accepted_version', 'legal_accepted_at']);
        });

        Schema::dropIfExists('legal_acceptances');
    }
};
