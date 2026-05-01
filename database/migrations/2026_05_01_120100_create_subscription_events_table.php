<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subscription_events — Stripe webhook idempotency log.
 *
 * Stripe retries failed webhooks for up to 3 days. Without an idempotency
 * record, a transient failure followed by a retry would re-credit a user,
 * re-suspend a tenant, or double-record a refund. The unique constraint
 * on stripe_event_id is the lock.
 *
 * Workspace_id is nullable: customer.* events for a customer that doesn't
 * exist in our DB (e.g. created via the dashboard then deleted) still need
 * to be recorded so we can audit and discard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')
                ->nullable()
                ->constrained('workspaces')
                ->nullOnDelete();
            $table->string('stripe_event_id', 128)->unique();
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
