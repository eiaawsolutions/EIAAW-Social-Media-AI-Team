<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // brands — one row per managed social account set (e.g. "EIAAW Workforce", "Acme Co").
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('website_url')->nullable();
            $table->string('industry')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->string('logo_url')->nullable();
            $table->json('config')->nullable(); // per-brand toggles (e.g. autoposting cadences, Blotato workspace ID)
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'archived_at']);
        });

        // brand_styles — the synthesised brand-style.md output of /brand-onboarding, plus its embedding for RAG.
        // Keeps history: each onboarding produces a new row with version++; current_version is the active one.
        Schema::create('brand_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->text('content_md'); // the synthesised brand-style.md content
            $table->json('voice_attributes')->nullable(); // {tone:[...], audience:[...], do:[...], dont:[...]}
            $table->json('palette')->nullable(); // canonical colours pulled from brand site
            $table->json('typography')->nullable();
            $table->json('evidence_sources')->nullable(); // [{url, scraped_at, summary}]
            $table->json('competitors')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'is_current']);
        });

        // pgvector embedding — separated from brand_styles to keep main table fast.
        // 1024-dim matches Voyage-3-large and Anthropic-recommended embedding dim. Switch to 1536 if using OpenAI.
        DB::statement('ALTER TABLE brand_styles ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX brand_styles_embedding_hnsw ON brand_styles USING hnsw (embedding vector_cosine_ops)');

        // brand_corpus — chunked chunks of historical posts + brand documents used for voice grounding (RAG source).
        Schema::create('brand_corpus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->enum('source_type', ['historical_post', 'website_page', 'style_guide', 'product_doc', 'manual_note']);
            $table->string('source_url')->nullable();
            $table->string('source_label')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->text('content');
            $table->json('metrics')->nullable(); // {likes, comments, shares, reach, engagement_rate} for historical_post
            $table->json('platform_meta')->nullable(); // platform-specific shape
            $table->timestamps();

            $table->index(['brand_id', 'source_type']);
        });
        DB::statement('ALTER TABLE brand_corpus ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX brand_corpus_embedding_hnsw ON brand_corpus USING hnsw (embedding vector_cosine_ops)');

        // platform_connections — OAuth tokens per platform per brand. Encrypted at app layer.
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->enum('platform', [
                'instagram', 'facebook', 'linkedin', 'tiktok', 'threads', 'x', 'youtube', 'pinterest',
            ]);
            $table->string('platform_account_id'); // platform-side ID
            $table->string('display_handle')->nullable();
            $table->text('access_token_encrypted'); // app-layer encrypted (Crypt::encrypt)
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->enum('status', ['active', 'expired', 'revoked', 'reauth_required'])->default('active');
            $table->string('blotato_account_id')->nullable(); // mirror in Blotato — fast-path publishing
            $table->timestamps();

            $table->unique(['brand_id', 'platform', 'platform_account_id']);
        });

        // embargoes — date-bound topic blocks. Compliance gate checks against these.
        Schema::create('embargoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('topic_keywords'); // list of keywords/regex to match against draft content
            $table->enum('action', ['block', 'require_review'])->default('block');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'starts_at', 'ends_at']);
        });

        // banned_phrases — brand-specific banned words/phrases. Compliance hard-blocks if matched.
        Schema::create('banned_phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('phrase');
            $table->boolean('is_regex')->default(false);
            $table->boolean('case_sensitive')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('brand_id');
        });

        // autonomy_settings — per platform per brand: green/amber/red lane defaults.
        Schema::create('autonomy_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->enum('platform', [
                'instagram', 'facebook', 'linkedin', 'tiktok', 'threads', 'x', 'youtube', 'pinterest',
            ])->nullable(); // null = default for ALL platforms in this brand
            $table->enum('default_lane', ['green', 'amber', 'red'])->default('amber');
            // green: auto-publish if Compliance passes. amber: human approval required. red: 2-human approval required.
            $table->json('green_lane_rules')->nullable(); // e.g. {pillars: ['educational','community']}
            $table->json('red_lane_rules')->nullable(); // e.g. {topics: ['regulatory','crisis']}
            $table->timestamps();

            $table->unique(['brand_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonomy_settings');
        Schema::dropIfExists('banned_phrases');
        Schema::dropIfExists('embargoes');
        Schema::dropIfExists('platform_connections');
        Schema::dropIfExists('brand_corpus');
        Schema::dropIfExists('brand_styles');
        Schema::dropIfExists('brands');
    }
};
