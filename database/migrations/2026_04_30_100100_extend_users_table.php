<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // EIAAW staff flag — bypasses workspace billing, has access to internal admin panel
            $table->boolean('is_super_admin')->default(false)->after('password');
            // Two-factor authentication
            $table->text('two_factor_secret')->nullable()->after('is_super_admin');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            // Last logged-in workspace — restored on next login
            $table->unsignedBigInteger('current_workspace_id')->nullable()->after('two_factor_confirmed_at');
            $table->timestamp('last_login_at')->nullable()->after('current_workspace_id');
            $table->string('avatar_url')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_super_admin',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'current_workspace_id',
                'last_login_at',
                'avatar_url',
            ]);
        });
    }
};
