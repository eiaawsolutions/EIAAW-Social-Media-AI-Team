<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // url path: /w/{slug}
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['internal', 'agency', 'solo'])->default('agency');
            // 'internal' = EIAAW HQ workspace; 'agency' = paying customer running multiple brands; 'solo' = single-brand client.
            $table->enum('plan', ['solo', 'studio', 'agency', 'eiaaw_internal'])->default('solo');
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('billplz_collection_id')->nullable();
            $table->string('logo_url')->nullable(); // for white-label client portals
            $table->json('settings')->nullable(); // freeform per-workspace config
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'editor', 'reviewer', 'viewer'])->default('editor');
            // owner: workspace owner (only one); admin: full read/write incl. billing; editor: drafts + schedules;
            // reviewer: amber/red lane approvals; viewer: read-only client portal access.
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('invitation_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        // FK from users.current_workspace_id (added in extend_users_table) — added now that workspaces exists.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_workspace_id')
                ->references('id')->on('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
        });
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
