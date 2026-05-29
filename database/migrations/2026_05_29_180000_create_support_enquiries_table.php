<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * support_enquiries — leads + enquiries captured by the floating "Talk to us"
 * form on the SMT landing page and inside the client/HQ panels.
 *
 * Lead Generation Contract (global): we store ONLY what the visitor actually
 * submitted — never a fabricated or inferred email/phone. The form requires a
 * real name, email and message; phone/company are optional and stored blank
 * (empty string), never guessed. No enrichment happens server-side.
 *
 * Tenancy: workspace_id/user_id are nullable because the landing-page form is
 * PUBLIC (no auth, no tenant). When the form is submitted from inside a panel
 * by a logged-in client or HQ user, we attach the workspace + user for context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_enquiries', function (Blueprint $table) {
            $table->id();

            // Context — who/where the enquiry came from. All nullable: the
            // landing form is public and unauthenticated.
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which surface raised it — drives triage + the chatbot mode that
            // referred them. 'landing' | 'client' | 'hq'.
            $table->string('surface', 16)->default('landing')->index();

            // Submitted contact — REAL values only (Lead Gen Contract).
            $table->string('name', 120);
            $table->string('email', 160);
            $table->string('phone', 40)->default('');
            $table->string('company', 160)->default('');
            $table->text('message');

            // Light forensics — never PII beyond what's above. IP is truncated
            // for rate-limit correlation only; UA helps spot bots.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('referer', 255)->nullable();

            // Triage state for the HQ panel.
            $table->string('status', 16)->default('new')->index(); // new | contacted | closed
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_enquiries');
    }
};
