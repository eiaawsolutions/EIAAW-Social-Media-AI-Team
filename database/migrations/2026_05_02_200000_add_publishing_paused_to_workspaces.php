<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-workspace publishing kill switch. When true, SubmitScheduledPost
     * job no-ops and PostsDispatchDue skips dispatching for the workspace.
     * Operator flips this on a brand crisis or pre-launch staging window.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('publishing_paused')->default(false)->after('suspended_reason');
            $table->timestamp('publishing_paused_at')->nullable()->after('publishing_paused');
            $table->string('publishing_paused_reason')->nullable()->after('publishing_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['publishing_paused', 'publishing_paused_at', 'publishing_paused_reason']);
        });
    }
};
