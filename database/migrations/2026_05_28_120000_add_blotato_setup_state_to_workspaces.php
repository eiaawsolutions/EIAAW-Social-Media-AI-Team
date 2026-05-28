<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // The /agency/platform-setup wizard moves a workspace through:
            //   (1) not requested  → button to request HQ provision
            //   (2) requested      → "awaiting HQ" empty state
            //   (3) credentials sent → show login URL + verify button
            //   (4) connected      → blotato_connected_at is already set
            $table->timestamp('blotato_setup_requested_at')->nullable()->after('blotato_connected_at');
            $table->string('blotato_login_url', 500)->nullable()->after('blotato_setup_requested_at');
            $table->timestamp('blotato_credentials_sent_at')->nullable()->after('blotato_login_url');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'blotato_setup_requested_at',
                'blotato_login_url',
                'blotato_credentials_sent_at',
            ]);
        });
    }
};
