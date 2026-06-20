<?php

namespace Tests\Unit;

use App\Agents\WriterAgent;
use Tests\TestCase;

/**
 * Upstream polish for the #56 Compliance crash: RepurposeAgent stored the LLM's
 * grounding_sources verbatim, and the model invented source_id="master_432" (a
 * reference to the master draft, not a brand_corpus id) — which crashed the
 * Compliance corpus lookup before PR #56 guarded it. This sanitizer strips a
 * non-numeric source_id from a corpus citation at the SOURCE (keeping the claim
 * + excerpt so Compliance's substring-match fallback still verifies it), so the
 * bogus id never reaches the gate. Defense-in-depth alongside the Compliance
 * guard. DB-free (pure static).
 *
 * renderGrowthObjectiveGuidanceBlock lives in the shared RendersWriterContext
 * trait; sanitizeGroundingSources is exposed via WriterAgent (which uses it).
 */
class SanitizeGroundingSourcesTest extends TestCase
{
    public function test_strips_non_numeric_source_id_from_corpus_citation(): void
    {
        $in = [
            ['claim' => 'argue with the model', 'source_type' => 'historical_post', 'source_id' => 'master_432', 'source_excerpt' => 'they argue with the model every week'],
        ];
        $out = WriterAgent::sanitizeGroundingSources($in);

        $this->assertCount(1, $out);
        // The bogus id is removed; the rest is preserved so substring-match still verifies.
        $this->assertArrayNotHasKey('source_id', $out[0]);
        $this->assertSame('historical_post', $out[0]['source_type']);
        $this->assertSame('they argue with the model every week', $out[0]['source_excerpt']);
    }

    public function test_keeps_numeric_corpus_source_id(): void
    {
        $in = [['claim' => 'x', 'source_type' => 'historical_post', 'source_id' => '417', 'source_excerpt' => 'verbatim phrase here that is long enough']];
        $out = WriterAgent::sanitizeGroundingSources($in);

        $this->assertSame('417', $out[0]['source_id']);
    }

    public function test_leaves_non_corpus_sources_untouched(): void
    {
        // brand_style / evidence_quote / calendar_entry are not queried as corpus ids.
        $in = [
            ['claim' => 'a', 'source_type' => 'brand_style', 'source_id' => 'voice', 'source_excerpt' => 'the brand voice section'],
            ['claim' => 'b', 'source_type' => 'evidence_quote', 'source_id' => 'q1', 'source_excerpt' => 'a quoted figure'],
        ];
        $out = WriterAgent::sanitizeGroundingSources($in);

        $this->assertSame('voice', $out[0]['source_id']);
        $this->assertSame('q1', $out[1]['source_id']);
    }

    public function test_handles_missing_and_malformed_entries(): void
    {
        $in = [
            ['claim' => 'no id here', 'source_type' => 'historical_post', 'source_excerpt' => 'phrase'],
            'not-an-array',
            ['source_type' => 'website_page', 'source_id' => 'master_9', 'source_excerpt' => 'p', 'claim' => 'c'],
        ];
        $out = WriterAgent::sanitizeGroundingSources($in);

        // Malformed scalar entry dropped; the two valid maps survive (one had no id,
        // one had its bogus id stripped).
        $this->assertCount(2, $out);
        $this->assertArrayNotHasKey('source_id', $out[1]);
    }

    public function test_empty_in_empty_out(): void
    {
        $this->assertSame([], WriterAgent::sanitizeGroundingSources([]));
    }
}
