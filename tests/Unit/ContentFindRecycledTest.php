<?php

namespace Tests\Unit;

use App\Console\Commands\ContentFindRecycled;
use Tests\TestCase;

/**
 * Pure-function tests for the recycling detector's similarity metric
 * (workstream E). No DB, no LLM.
 */
class ContentFindRecycledTest extends TestCase
{
    public function test_identical_bodies_are_maximally_similar(): void
    {
        $body = 'The hiring shortlist comes from the AI. The decision never does.';
        $this->assertEqualsWithDelta(1.0, ContentFindRecycled::similarity($body, $body), 0.0001);
    }

    public function test_reworded_same_idea_scores_high(): void
    {
        // Same underlying claim, different wording — the thematic recycling we
        // want the theme threshold (0.6) to catch.
        $a = 'The hiring shortlist comes from the AI, but the final decision always stays with your manager.';
        $b = 'Your manager always makes the final hiring decision — the AI only produces the shortlist.';

        $this->assertGreaterThan(0.5, ContentFindRecycled::similarity($a, $b));
    }

    public function test_distinct_topics_score_low(): void
    {
        $a = 'Latte art for beginners: three pours to master this weekend at the cafe.';
        $b = 'Our shift-scheduling engine flags coverage gaps before Monday morning.';

        $this->assertLessThan(0.3, ContentFindRecycled::similarity($a, $b));
    }

    public function test_empty_bodies_are_not_similar(): void
    {
        $this->assertSame(0.0, ContentFindRecycled::similarity('', 'anything here at all'));
        $this->assertSame(0.0, ContentFindRecycled::similarity('anything here at all', ''));
    }

    public function test_hashtags_and_urls_are_ignored_so_they_do_not_inflate_similarity(): void
    {
        // Two DIFFERENT posts that share only hashtags + a link should NOT read
        // as similar just because of the boilerplate tail.
        $a = 'Our new espresso blend lands Friday. #coffee #cafe https://x.co/a';
        $b = 'Weekend jazz night returns this Saturday. #coffee #cafe https://x.co/a';

        $this->assertLessThan(0.3, ContentFindRecycled::similarity($a, $b));
    }
}
