<?php

namespace Tests\Unit;

use App\Agents\StrategistAgent;
use Tests\TestCase;

/**
 * Pure-function tests for the Strategist's intra-batch dedup — the post-plan
 * pass that catches same-call self-repetition (one 30-entry generation drifting
 * into two entries that make the same point under different topic strings).
 * No DB, no LLM: StrategistAgent::dedupeEntriesWithinBatch is a pure static.
 */
class StrategistIntraBatchDedupTest extends TestCase
{
    /** Minimal entry factory — only the idea-bearing fields matter here. */
    private function entry(string $topic, string $angle = '', string $contentAngle = ''): array
    {
        return array_filter([
            'topic' => $topic,
            'angle' => $angle,
            'content_angle' => $contentAngle,
        ], static fn ($v) => $v !== '');
    }

    public function test_distinct_entries_pass_through_untouched_and_in_order(): void
    {
        $entries = [
            $this->entry('Latte art for beginners', 'step-by-step at home'),
            $this->entry('Our new single-origin Ethiopian roast', 'tasting notes + sourcing'),
            $this->entry('Meet the weekend barista', 'staff spotlight'),
        ];

        $result = StrategistAgent::dedupeEntriesWithinBatch($entries);

        $this->assertCount(3, $result['kept']);
        $this->assertSame([], $result['dropped'], 'no drops expected for distinct entries');
        // Order preserved.
        $this->assertSame('Latte art for beginners', $result['kept'][0]['topic']);
        $this->assertSame('Meet the weekend barista', $result['kept'][2]['topic']);
    }

    public function test_same_idea_different_wording_is_collapsed_keeping_earliest(): void
    {
        $entries = [
            $this->entry(
                'How our AI Impact Assessment decides whether to take the work',
                'the four questions we ask before any engagement',
            ),
            $this->entry('A completely different topic about pricing', 'flat per-brand math'),
            // Same underlying idea as entry 0, reworded topic string.
            $this->entry(
                'The assessment we run before accepting an AI engagement — the four questions',
                'what our AI Impact Assessment surfaces before we take the work',
            ),
        ];

        $result = StrategistAgent::dedupeEntriesWithinBatch($entries);

        // The reworded restatement (index 2) is dropped; the earliest (index 0) kept.
        $this->assertCount(2, $result['kept']);
        $this->assertSame(
            'How our AI Impact Assessment decides whether to take the work',
            $result['kept'][0]['topic'],
        );
        $this->assertSame('A completely different topic about pricing', $result['kept'][1]['topic']);

        $this->assertCount(1, $result['dropped']);
        $this->assertStringContainsString('assessment', strtolower($result['dropped'][0]['topic']));
        $this->assertSame(
            'How our AI Impact Assessment decides whether to take the work',
            $result['dropped'][0]['similar_to'],
        );
        $this->assertGreaterThanOrEqual(
            StrategistAgent::INTRA_BATCH_DEDUP_THRESHOLD,
            $result['dropped'][0]['score'],
        );
    }

    public function test_below_threshold_near_misses_are_kept(): void
    {
        // Same pillar/domain (coffee) but genuinely different ideas — must NOT
        // collapse. Distinct nouns keep Jaccard under the 0.6 cutoff.
        $entries = [
            $this->entry('Cold brew steeping times', 'why 18 hours beats 12'),
            $this->entry('Choosing a burr grinder', 'conical versus flat burrs explained'),
        ];

        $result = StrategistAgent::dedupeEntriesWithinBatch($entries);

        $this->assertCount(2, $result['kept']);
        $this->assertSame([], $result['dropped']);
    }

    public function test_blank_topics_do_not_false_positive_collapse(): void
    {
        // Two entries with no idea-bearing text must both survive — we don't
        // silently merge blanks (downstream validation owns empties).
        $entries = [
            ['topic' => '', 'angle' => ''],
            ['topic' => '', 'angle' => ''],
            $this->entry('A real topic', 'a real angle'),
        ];

        $result = StrategistAgent::dedupeEntriesWithinBatch($entries);

        $this->assertCount(3, $result['kept']);
        $this->assertSame([], $result['dropped']);
    }

    public function test_threshold_is_configurable_and_strict_threshold_drops_less(): void
    {
        $entries = [
            $this->entry('Latte art hearts and rosettas', 'pouring technique basics'),
            $this->entry('Latte art tulips and swans', 'advanced pouring technique'),
        ];

        // A very high threshold treats these two as distinct.
        $strict = StrategistAgent::dedupeEntriesWithinBatch($entries, 0.95);
        $this->assertCount(2, $strict['kept']);

        // A very low threshold treats them as the same idea (shared tokens).
        $loose = StrategistAgent::dedupeEntriesWithinBatch($entries, 0.2);
        $this->assertCount(1, $loose['kept']);
        $this->assertCount(1, $loose['dropped']);
    }

    public function test_empty_input_returns_empty(): void
    {
        $result = StrategistAgent::dedupeEntriesWithinBatch([]);
        $this->assertSame([], $result['kept']);
        $this->assertSame([], $result['dropped']);
    }
}
