<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use Tests\TestCase;

/**
 * Regression for a prod crash surfaced by the Repurpose P0 change: a derivative
 * draft's grounding_sources cited source_type=historical_post with
 * source_id="master_432" (a synthetic reference to the master draft, NOT a
 * brand_corpus row id). ComplianceAgent::corpusVerifies queried
 * `brand_corpus WHERE id = 'master_432'`; the id column is bigint, so Postgres
 * threw SQLSTATE 22P02 (invalid input for bigint) and the ENTIRE compliance gate
 * crashed for any Repurpose derivative citing the master.
 *
 * Fix: only attempt the id-based lookup when source_id is a plain numeric corpus
 * id; non-numeric ids skip straight to the substring-excerpt fallback. DB-free.
 */
class ComplianceSourceIdGuardTest extends TestCase
{
    public function test_numeric_ids_are_queryable(): void
    {
        $this->assertTrue(ComplianceAgent::isQueryableCorpusId('123'));
        $this->assertTrue(ComplianceAgent::isQueryableCorpusId(123));
        $this->assertTrue(ComplianceAgent::isQueryableCorpusId('  456  ')); // trimmed
    }

    public function test_non_numeric_ids_are_not_queryable(): void
    {
        // The exact value that crashed prod.
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId('master_432'));
        // Other non-corpus shapes the model emits.
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId('brand_style'));
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId(''));
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId(null));
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId('12abc'));
        $this->assertFalse(ComplianceAgent::isQueryableCorpusId('1.5'));
    }
}
