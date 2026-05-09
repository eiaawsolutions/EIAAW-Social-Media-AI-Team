<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds research_brief (jsonb) to calendar_entries.
 *
 * Shape (set by ResearcherAgent, consumed by WriterAgent):
 *   {
 *     "angles": [
 *       {
 *         "hook": "...",                 // first-line hook
 *         "thesis": "...",               // one-sentence specific take
 *         "evidence": "...",             // the proof (metric / story / source)
 *         "tension": "...",              // what makes it interesting
 *         "audience": "...",             // who it's for
 *         "source_ids": [12, 47]         // brand_corpus item ids cited (0..N)
 *       }
 *     ],
 *     "generated_at": "2026-05-09T...",
 *     "model_id": "claude-sonnet-4-6",
 *     "prompt_version": "researcher.v1.0",
 *     "cost_usd": 0.012
 *   }
 *
 * Why on the calendar entry (not a separate research table):
 *   - 1:1 with the entry. Always loaded together, never queried alone.
 *   - Re-running the Researcher overwrites the brief — versioning lives in
 *     audit_log via the agent.researcher.completed action.
 *   - The Writer consumes it at draft-time directly from the entry row.
 *   - Same pattern as drafts.branding_payload (single-row JSON for 1:1 data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_entries', function (Blueprint $table) {
            $table->json('research_brief')->nullable()->after('visual_direction');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_entries', function (Blueprint $table) {
            $table->dropColumn('research_brief');
        });
    }
};
