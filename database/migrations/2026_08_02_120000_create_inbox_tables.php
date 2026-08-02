<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The L4 distribution actuator's storage: inbound engagement, and the replies we
 * draft for it.
 *
 * Verified live 2026-08-01 — Metricool's public API exposes the full inbox on
 * the SAME token we already publish with (GET/POST /v2/inbox/conversations and
 * /v2/inbox/post-comments, round-trip proven). Until then the growth ladder
 * topped out at L3 because nothing could reach people; meanwhile prod had a
 * paying client with 19 post-comments ALL pending, oldest 2025-11-15, and 9
 * pending DM threads. People were talking to the accounts and nothing answered.
 *
 * REPLY WINDOWS ARE HARD DEADLINES (Metricool docs): comments 24h, DMs 7d. That
 * is why `window_expires_at` is a stored column and not a computed display
 * value — the ingest cadence, the approval UI ordering, and the expiry sweep all
 * key off it, and a reply drafted after the window closes is wasted spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // 'dm' => /v2/inbox/conversations, 'comment' => /v2/inbox/post-comments.
            // The two have different reply endpoints AND different reply windows,
            // so the type is load-bearing, not cosmetic.
            $table->string('conversation_type', 16);
            $table->string('provider', 32);              // INSTAGRAM, FACEBOOK, TWITTER, ...

            // Metricool's id. For a DM this is conversationId; for a comment it
            // is the objectId the reply endpoint wants. Unique per brand+type.
            $table->string('external_id', 255);

            // Who to address. DM replies REQUIRE a recipient id; comment replies
            // do not, so this is nullable.
            $table->string('recipient_external_id', 255)->nullable();
            $table->string('participant_name', 255)->nullable();

            $table->string('status', 16)->default('PENDING'); // PENDING | READ (Metricool's own)
            $table->text('last_message_text')->nullable();
            $table->boolean('last_message_from_us')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('window_expires_at')->nullable();
            $table->timestamp('our_last_reply_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->json('raw')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'conversation_type', 'external_id'], 'inbox_conv_brand_type_ext_unique');
            $table->index(['brand_id', 'status']);
            $table->index(['window_expires_at']);
        });

        Schema::create('inbox_reply_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            $table->text('body');

            // pending_approval — drafted, waiting on a human (the default lane)
            // approved         — a human clicked send; the send job will pick it up
            // sent             — delivered, provider accepted
            // rejected         — a human declined it
            // expired          — the reply window closed before anyone acted
            // failed           — the provider refused the send
            $table->string('status', 24)->default('pending_approval');

            // Set when the agent judged that no reply should be sent at all.
            // A drafted non-answer is better than an agent inventing something
            // to say — the Writer's truthfulness rules apply to DMs too.
            $table->boolean('recommends_no_reply')->default(false);
            $table->text('reasoning')->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();

            $table->string('model_id')->nullable();
            $table->string('prompt_version')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index(['inbox_conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_reply_drafts');
        Schema::dropIfExists('inbox_conversations');
    }
};
