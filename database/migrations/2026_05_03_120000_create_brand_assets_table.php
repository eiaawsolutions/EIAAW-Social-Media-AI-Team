<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Brand asset library — uploaded brand-approved images and videos that
     * the DesignerAgent and VideoAgent pick from BEFORE falling back to AI
     * generation. The zero-cost-per-post path: customer uploads their own
     * photos / Canva exports / b-roll, agents semantically match the right
     * asset to each draft via pgvector cosine on the entry's
     * topic + visual_direction.
     */
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // image | video — drives which agent picks this row.
            $table->string('media_type');

            // upload | ai_generated | stock_import (v1.1)
            $table->string('source');

            // Storage. R2 if configured, public local disk fallback.
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('public_url');
            $table->string('thumbnail_url')->nullable();

            // File metadata.
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->integer('width_px')->nullable();
            $table->integer('height_px')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Semantic discovery. tags + description generated on upload via
            // Claude vision; embedding from Voyage on the description+tags concat.
            $table->json('tags')->nullable();
            $table->text('description')->nullable();
            // pgvector embedding column added separately below.

            // Lifecycle counters — Picker uses these as tie-breakers (prefer
            // assets that haven't been used recently; prefer brand-approved).
            $table->boolean('brand_approved')->default(true);
            $table->integer('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'media_type', 'archived_at']);
            $table->index(['brand_id', 'last_used_at']);
        });

        // pgvector 1024-dim to match BrandStyle/BrandCorpusItem embeddings.
        DB::statement('ALTER TABLE brand_assets ADD COLUMN embedding vector(1024)');
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
