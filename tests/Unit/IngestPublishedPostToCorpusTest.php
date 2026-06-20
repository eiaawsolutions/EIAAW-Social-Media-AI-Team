<?php

namespace Tests\Unit;

use App\Jobs\IngestPublishedPostToCorpus;
use Tests\TestCase;

/**
 * DB-free guard tests for the publish→corpus ingest job. The suite never
 * touches the DB (local .env points at prod Postgres + pgvector can't migrate
 * under sqlite), so the integration path — embed + insert + dedup-with-data —
 * is verified by the live smoke in the plan. Here we lock the pure decision
 * that's most likely to regress: which bodies are substantial enough to index.
 * The backfill command shares this exact predicate, so they can't drift apart.
 */
class IngestPublishedPostToCorpusTest extends TestCase
{
    public function test_substantial_body_is_indexable(): void
    {
        $body = 'A real published caption with enough substance to matter for dedup.';
        $this->assertTrue(IngestPublishedPostToCorpus::bodyIsIndexable($body));
    }

    public function test_stub_and_empty_bodies_are_not_indexable(): void
    {
        $min = IngestPublishedPostToCorpus::MIN_BODY_CHARS;

        $this->assertFalse(IngestPublishedPostToCorpus::bodyIsIndexable(null));
        $this->assertFalse(IngestPublishedPostToCorpus::bodyIsIndexable(''));
        $this->assertFalse(IngestPublishedPostToCorpus::bodyIsIndexable('   '));
        // One char under the threshold is rejected.
        $this->assertFalse(IngestPublishedPostToCorpus::bodyIsIndexable(str_repeat('x', $min - 1)));
        // Whitespace doesn't count toward the length (trimmed first).
        $this->assertFalse(IngestPublishedPostToCorpus::bodyIsIndexable('  '.str_repeat('x', $min - 1).'  '));
    }

    public function test_exactly_at_the_threshold_is_indexable(): void
    {
        $min = IngestPublishedPostToCorpus::MIN_BODY_CHARS;
        $this->assertTrue(IngestPublishedPostToCorpus::bodyIsIndexable(str_repeat('x', $min)));
    }
}
