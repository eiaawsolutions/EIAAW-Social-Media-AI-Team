<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds operator-supplied business facts to brands: business_locations (jsonb)
 * and audience_profile (jsonb). These enrich the brand voice the Writer and
 * Strategist agents reason over.
 *
 * Why on `brands` and NOT on `brand_styles`:
 *   brand_styles is the AI-SYNTHESISED voice — a new versioned row is written
 *   on every onboarding/refresh, and the OnboardingAgent regenerates it from
 *   website evidence. Operator-entered facts (where the business operates, who
 *   it serves) are durable identity that must SURVIVE every re-synthesis, so
 *   they live on the stable brands row. They are injected into the agent
 *   prompts ABOVE brand-style.md as an authoritative block — operator facts
 *   override the AI's guesses, never the reverse.
 *
 * Shapes (see App\Models\Brand::brandFactsBlock for the prompt rendering):
 *
 *   business_locations:
 *     [
 *       {"area": "Kuala Lumpur", "country": "Malaysia", "is_primary": true,  "notes": "HQ + flagship café"},
 *       {"area": "Penang",       "country": "Malaysia", "is_primary": false, "notes": "second outlet"}
 *     ]
 *
 *   audience_profile:
 *     {
 *       "description": "Time-poor urban professionals 25-40 who treat good coffee as a daily ritual.",
 *       "segments": ["Young professionals", "Remote workers", "Café-hoppers"],
 *       "geo_focus": "Klang Valley, Malaysia"
 *     }
 *
 * Both nullable — existing brands keep working with NULL (brandFactsBlock
 * renders an empty string, so the prompt is byte-identical to before until an
 * operator fills the fields in). No backfill needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('business_locations')->nullable()->after('competitor_intel_config');
            $table->json('audience_profile')->nullable()->after('business_locations');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['business_locations', 'audience_profile']);
        });
    }
};
