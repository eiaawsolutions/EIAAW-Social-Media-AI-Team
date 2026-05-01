<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // content_calendars — one per month per brand. Built by the Strategist agent.
        Schema::create('content_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('label'); // e.g. "May 2026"
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->json('pillar_mix'); // {educational: 0.4, community: 0.3, promotional: 0.2, behind_the_scenes: 0.1}
            $table->json('format_mix'); // {single_image: 0.3, carousel: 0.3, reel: 0.25, text_only: 0.15}
            $table->json('platform_mix')->nullable();
            $table->enum('status', ['draft', 'in_review', 'approved', 'archived'])->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'period_starts_on']);
        });

        // calendar_entries — individual planned posts. Drafts hang off these.
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_calendar_id')->constrained('content_calendars')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete(); // denormalised for query speed + RLS
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->string('topic');
            $table->text('angle');
            $table->string('pillar'); // educational | community | promotional | behind_the_scenes | thought_leadership
            $table->string('format'); // single_image | carousel | reel | text_only | video | story
            $table->json('platforms'); // ['instagram', 'linkedin']
            $table->string('objective')->nullable(); // awareness | engagement | traffic | leads | retention
            $table->text('visual_direction')->nullable(); // handoff note for Designer agent
            $table->enum('status', ['planned', 'drafted', 'scheduled', 'published', 'skipped'])->default('planned');
            $table->timestamps();

            $table->index(['brand_id', 'scheduled_date']);
            $table->index(['content_calendar_id', 'status']);
        });

        // drafts — every AI-generated caption, image, video, thread. Heavy provenance.
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('calendar_entry_id')->nullable()->constrained('calendar_entries')->nullOnDelete();
            $table->enum('platform', [
                'instagram', 'facebook', 'linkedin', 'tiktok', 'threads', 'x', 'youtube', 'pinterest',
            ]);
            $table->enum('content_type', ['caption', 'thread', 'longform', 'image', 'video', 'reel', 'story']);
            $table->longText('body'); // caption text, thread JSON, or asset descriptor
            $table->json('platform_payload')->nullable(); // platform-specific shape (LinkedIn doc carousel json, X thread parts, etc.)
            $table->json('hashtags')->nullable();
            $table->json('mentions')->nullable();
            $table->string('asset_url')->nullable(); // R2 url for primary image/video
            $table->json('asset_urls')->nullable(); // multi-asset (carousel, multi-image)

            // ── Provenance (the "receipts") ────────────────────────────────────
            $table->string('agent_role'); // strategist | writer | designer | community
            $table->string('model_id'); // claude-sonnet-4-6 / fal-ai/flux-pro/v1.1
            $table->string('prompt_version'); // e.g. "writer.linkedin.v3.2"
            $table->json('prompt_inputs')->nullable(); // full structured input (calendar entry id, brand_style version, embargo list snapshot ids)
            $table->json('grounding_sources')->nullable(); // [{brand_corpus_id, similarity, chunk_excerpt}, ...]
            $table->json('competitor_refs')->nullable(); // [{url, key_quote}]
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // ── Approval state ─────────────────────────────────────────────────
            $table->enum('status', [
                'generating', 'compliance_pending', 'compliance_failed',
                'awaiting_approval', 'approved', 'rejected',
                'scheduled', 'published', 'failed_to_publish', 'archived',
            ])->default('generating');
            $table->enum('lane', ['green', 'amber', 'red'])->default('amber');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index(['brand_id', 'platform', 'status']);
            $table->index(['calendar_entry_id', 'platform']);
        });

        // compliance_checks — one row per check per draft. Fail-closed: any single fail keeps the draft held.
        Schema::create('compliance_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('check_type'); // brand_voice | factual_grounding | embargo | dedup | banned_phrase | image_brand_dna
            $table->decimal('score', 5, 4)->nullable(); // 0.0000 - 1.0000
            $table->decimal('threshold', 5, 4)->nullable();
            $table->enum('result', ['pass', 'fail', 'warning', 'error'])->default('pass');
            $table->text('reason')->nullable(); // human-readable explanation
            $table->json('details')->nullable(); // full diagnostic payload (matched embargo id, top similar prior post, etc.)
            $table->string('model_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['draft_id', 'check_type']);
            $table->index(['brand_id', 'result']);
        });

        // scheduled_posts — drafts queued for Blotato (or native API). One row per platform per draft.
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('drafts')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('platform_connection_id')->constrained('platform_connections')->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->enum('status', ['queued', 'submitting', 'submitted', 'published', 'failed', 'cancelled'])->default('queued');
            $table->string('blotato_post_id')->nullable();
            $table->string('platform_post_id')->nullable(); // post ID returned after publish
            $table->string('platform_post_url')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'scheduled_for']);
            $table->index(['status', 'scheduled_for']);
        });

        // performance_uploads — CSV/manual data for monthly review. NEVER auto-generated.
        Schema::create('performance_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['csv_export', 'screenshot', 'manual_entry', 'api_pull']);
            $table->string('platform')->nullable();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('original_filename')->nullable();
            $table->string('file_url')->nullable();
            $table->json('parsed_data')->nullable(); // normalised row-level metrics
            $table->json('summary')->nullable(); // {total_reach, total_engagement, top_post_id, ...}
            $table->timestamps();

            $table->index(['brand_id', 'period_starts_on']);
        });

        // ai_costs — per-call ledger for transparent pass-through to clients.
        Schema::create('ai_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained('drafts')->nullOnDelete();
            $table->string('agent_role');
            $table->string('provider'); // anthropic | fal | blotato
            $table->string('model_id');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('image_count')->nullable();
            $table->decimal('cost_usd', 12, 6);
            $table->decimal('cost_myr', 12, 4)->nullable();
            $table->timestamp('called_at');
            $table->timestamps();

            $table->index(['workspace_id', 'called_at']);
            $table->index(['brand_id', 'called_at']);
            $table->index(['provider', 'called_at']);
        });

        // pipeline_runs — replaces Inngest. Each multi-step agent workflow execution is a run.
        // Workers tail this table for `state IN ('queued','retry') AND next_run_at <= NOW()`.
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->string('workflow'); // brand_onboarding | content_calendar | draft_caption | draft_image | publish | review
            $table->foreignId('brand_id')->nullable()->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->morphs('subject'); // optional related entity (calendar_entry, draft, etc.)
            $table->json('input'); // workflow input payload
            $table->json('state_data')->nullable(); // accumulated state across steps
            $table->string('current_step')->nullable();
            $table->enum('state', [
                'queued', 'running', 'awaiting_human', 'retry', 'completed', 'failed', 'cancelled',
            ])->default('queued');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('error_history')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['state', 'next_run_at']);
            $table->index(['workflow', 'state']);
            $table->index(['brand_id', 'state']);
        });

        // audit_log — append-only. Every state-changing action recorded with actor + before/after.
        // Postgres trigger prevents UPDATE/DELETE.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->default('user'); // user | system | agent
            $table->string('action'); // e.g. draft.approved | draft.rejected | calendar.published | brand.created
            $table->morphs('subject');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable(); // ip, user_agent, request_id
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['brand_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        // Postgres trigger: audit_log is append-only. Block UPDATE and DELETE.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_block_modification()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only — % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_no_update
            BEFORE UPDATE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_block_modification();
        SQL);
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            CREATE TRIGGER audit_log_no_delete
            BEFORE DELETE ON audit_log
            FOR EACH ROW EXECUTE FUNCTION audit_log_block_modification();
        SQL);
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS audit_log_no_update ON audit_log');
        \Illuminate\Support\Facades\DB::statement('DROP TRIGGER IF EXISTS audit_log_no_delete ON audit_log');
        \Illuminate\Support\Facades\DB::statement('DROP FUNCTION IF EXISTS audit_log_block_modification()');

        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pipeline_runs');
        Schema::dropIfExists('ai_costs');
        Schema::dropIfExists('performance_uploads');
        Schema::dropIfExists('scheduled_posts');
        Schema::dropIfExists('compliance_checks');
        Schema::dropIfExists('drafts');
        Schema::dropIfExists('calendar_entries');
        Schema::dropIfExists('content_calendars');
    }
};
