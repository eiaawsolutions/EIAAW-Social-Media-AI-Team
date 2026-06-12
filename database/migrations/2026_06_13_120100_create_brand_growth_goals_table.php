<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-set growth goals per brand. The GrowthStrategistAgent reads active
 * goals and biases its narration + recommended objective mix toward them (e.g.
 * a link_clicks goal → bias traffic/leads objectives + CTA-heavy guidance), and
 * the dashboard card shows progress. Progress is computed in PHP from REAL
 * current values (AccountGrowthService / post_metrics) — never fabricated.
 *
 * baseline_value is snapshotted at creation so progress is measured against the
 * real starting point, not assumed zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_growth_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // followers | reach | engagement_rate | link_clicks | profile_visits
            $table->string('target_metric', 32);
            // null = account-wide; else a specific network (instagram, linkedin, …)
            $table->string('platform', 32)->nullable();

            $table->bigInteger('target_value');
            $table->bigInteger('baseline_value')->default(0); // snapshot at creation

            $table->date('window_starts_on');
            $table->date('window_ends_on');

            // active | achieved | missed | archived
            $table->string('status', 16)->default('active');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_growth_goals');
    }
};
