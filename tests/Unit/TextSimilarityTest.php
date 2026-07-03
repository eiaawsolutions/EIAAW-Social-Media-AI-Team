<?php

namespace Tests\Unit;

use App\Support\TextSimilarity;
use Tests\TestCase;

/**
 * Pure-function tests for the shared token-set (Jaccard) similarity helper that
 * both the recycling detector (ContentFindRecycled) and the Strategist's
 * intra-batch dedup rely on.
 */
class TextSimilarityTest extends TestCase
{
    public function test_identical_text_scores_one(): void
    {
        $s = 'The espresso extraction ratio matters more than the grind setting.';
        $this->assertEqualsWithDelta(1.0, TextSimilarity::jaccard($s, $s), 0.0001);
    }

    public function test_reworded_same_idea_scores_high(): void
    {
        $a = 'Why the espresso extraction ratio matters more than grind setting';
        $b = 'The extraction ratio matters far more than your espresso grind setting';
        $this->assertGreaterThan(0.5, TextSimilarity::jaccard($a, $b));
    }

    public function test_different_ideas_score_low(): void
    {
        $a = 'Choosing a conical burr grinder for pour-over coffee';
        $b = 'Our new loyalty programme launches next Monday';
        $this->assertLessThan(0.3, TextSimilarity::jaccard($a, $b));
    }

    public function test_empty_side_scores_zero(): void
    {
        $this->assertSame(0.0, TextSimilarity::jaccard('', 'anything here at all'));
        $this->assertSame(0.0, TextSimilarity::jaccard('anything here at all', ''));
    }

    public function test_hashtags_urls_and_stop_words_do_not_inflate_similarity(): void
    {
        // Only the stop-words / hashtags / urls overlap — real content differs.
        $a = 'Cold brew steeping guide #coffee https://x.com/a and the you are of';
        $b = 'Burr grinder buying advice #coffee https://x.com/b and the you are of';
        $this->assertLessThan(0.3, TextSimilarity::jaccard($a, $b));
    }

    public function test_tokens_strips_noise_and_short_and_stop_words(): void
    {
        $tokens = TextSimilarity::tokens('The BEST cold-brew, at #home! https://x.com/z (really)');
        // Lowercased, punctuation/url/hashtag stripped, stop + short words dropped.
        $this->assertContains('best', $tokens);
        $this->assertContains('cold', $tokens);
        $this->assertContains('brew', $tokens);
        $this->assertContains('really', $tokens);
        $this->assertNotContains('the', $tokens);   // stop word
        $this->assertNotContains('at', $tokens);    // short + stop
        $this->assertNotContains('home', $tokens);  // was inside a #hashtag → stripped
    }
}
