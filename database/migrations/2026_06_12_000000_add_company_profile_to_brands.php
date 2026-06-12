<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the operator-supplied company / brand profile to brands:
 * company_profile (text) and company_profile_file (jsonb).
 *
 * Sits alongside business_locations + audience_profile (see
 * 2026_06_09_140000_add_business_facts_to_brands) as another piece of durable,
 * operator-entered ground truth. It enriches the brand voice the Writer and
 * Strategist agents reason over — positioning, products, brand voice, audience.
 *
 * Why on `brands` and NOT on `brand_styles`:
 *   brand_styles is the AI-SYNTHESISED voice — re-generated on every onboarding
 *   refresh. Operator-entered facts must SURVIVE every re-synthesis, so they
 *   live on the stable brands row and are injected into the agent prompts ABOVE
 *   brand-style.md as an authoritative block (see App\Models\Brand::brandFactsBlock).
 *
 * Two columns, not six:
 *   - company_profile (text): the PASTED PROFILE TEXT — the actual AI input.
 *     First-class so it's queryable/inspectable and rendered by brandFactsBlock.
 *   - company_profile_file (jsonb): an ARCHIVAL metadata bag for the optional
 *     uploaded source document (PDF/DOCX/slides). The file is stored durably on
 *     R2 for the operator's record only — it is NEVER parsed and NEVER read
 *     back; the AI is grounded by company_profile, not this file. The bag is
 *     always written/read together and never filtered, matching the table's
 *     existing JSON-bag convention (config, business_locations, etc.).
 *
 *     Shape:
 *       {
 *         "disk": "r2", "path": "company-profiles/8/ab12…-profile.pdf",
 *         "url": "https://smt-assets.eiaawsolutions.com/…",
 *         "filename": "company-profile.pdf", "mime": "application/pdf",
 *         "size": 184320, "uploaded_at": "2026-06-12T10:30:00+00:00"
 *       }
 *
 * Both nullable — existing brands keep working with NULL (brandFactsBlock
 * renders an empty string for an empty company_profile, so the prompt is
 * byte-identical to before until an operator fills the field in). No backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->text('company_profile')->nullable()->after('audience_profile');
            $table->json('company_profile_file')->nullable()->after('company_profile');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['company_profile', 'company_profile_file']);
        });
    }
};
