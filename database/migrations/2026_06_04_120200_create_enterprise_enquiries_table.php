<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * enterprise_enquiries — leads from the dedicated /enterprise "Talk to us" page.
 *
 * Distinct from support_enquiries (the floating chatbot's generic lead form)
 * because the Enterprise tier qualification needs richer, tailored fields the
 * sales team uses to scope a bespoke plan: company size, brand count, monthly
 * video volume, and budget band. Keeping it a separate table avoids stuffing the
 * generic enquiry form with sales-only columns and gives HQ a clean Enterprise
 * pipeline to triage.
 *
 * Lead Generation Contract (global): store ONLY what the visitor actually
 * submitted. Name + work email + company + message are required and real; phone
 * and the scoping fields are optional and stored as their declared default when
 * absent — never guessed or enriched server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_enquiries', function (Blueprint $table) {
            $table->id();

            // Submitted contact — REAL values only (Lead Gen Contract).
            $table->string('name', 120);
            $table->string('email', 160);            // work email
            $table->string('phone', 40)->default('');
            $table->string('company', 160);
            $table->string('website', 200)->default('');

            // Sales scoping fields — drive the bespoke-plan conversation. All
            // optional (visitor may not know yet); stored blank/zero, never
            // fabricated. company_size/budget_band are free-ish select strings.
            $table->string('company_size', 40)->default('');   // e.g. "1-10", "11-50", "51-200", "200+"
            $table->unsignedInteger('brands_needed')->nullable();
            $table->unsignedInteger('videos_per_month')->nullable();
            $table->string('budget_band', 40)->default('');     // e.g. "<RM10k", "RM10-30k", "RM30k+"

            $table->text('message');

            // Light forensics — never PII beyond the above. IP truncated/hashed
            // for rate-limit correlation only; UA helps spot bots.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('referer', 255)->nullable();

            // Triage state for the HQ panel.
            $table->string('status', 16)->default('new')->index(); // new | contacted | qualified | closed
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_enquiries');
    }
};
