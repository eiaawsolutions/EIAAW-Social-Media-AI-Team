<?php

namespace Tests\Unit;

use App\Agents\OptimizerAgent;
use Tests\TestCase;

/**
 * OptimizerAgent scoring — within-platform normalisation.
 *
 * Background (prod audit 2026-07-31): the original score was
 *   impressions + 5*likes + 10*comments + 20*shares + 30*saves
 * summed per dimension. At EIAAW's real volumes (305 measured posts,
 * 12,526 impressions but only 384 total engagements) three defects compounded:
 *
 *   1. The impressions term swamped every engagement term, so the #1 "top
 *      performer" handed to the Strategist was a LinkedIn carousel with 951
 *      impressions and ZERO engagement. Ranking the same posts by engagement
 *      gave 0/20 overlap with the Optimizer's top 20.
 *   2. Impressions are not commensurable across networks (median: TikTok 111,
 *      LinkedIn 37, Instagram 13, Facebook 1), yet pillar/format scores were
 *      summed ACROSS platforms — so the "winning pillar" was really "whichever
 *      pillar happened to land on LinkedIn".
 *   3. Summing (not averaging) per dimension meant posting more of something
 *      made it win regardless of per-post quality — a mirror of past volume,
 *      not a signal.
 *
 * These tests lock the corrected behaviour. All pure + DB-free.
 */
class OptimizerScoringTest extends TestCase
{
    /** @return array<string,mixed> */
    private function metric(string $platform, int $impressions, int $engagement, ?string $pillar = null, ?string $format = null): array
    {
        return [
            'platform' => $platform,
            'impressions' => $impressions,
            'engagement' => $engagement,
            'pillar' => $pillar,
            'format' => $format,
        ];
    }

    // ─── 1. Engagement must outrank bare reach on the same platform ─────────

    public function test_high_reach_zero_engagement_post_does_not_outrank_an_engaging_post(): void
    {
        // The real prod pair: a 951-impression/0-engagement LinkedIn carousel
        // vs a 9-impression/4-engagement LinkedIn text post.
        $posts = [
            $this->metric('linkedin', 951, 0),
            $this->metric('linkedin', 9, 4),
            $this->metric('linkedin', 37, 1),
        ];

        $values = OptimizerAgent::normalisedPostValues($posts);

        $this->assertGreaterThan(
            $values[0],
            $values[1],
            'A post with 4 interactions must score above one with 951 impressions and none.',
        );
    }

    public function test_values_are_bounded_zero_to_one(): void
    {
        $posts = [
            $this->metric('linkedin', 951, 0),
            $this->metric('linkedin', 9, 4),
            $this->metric('instagram', 13, 2),
        ];

        foreach (OptimizerAgent::normalisedPostValues($posts) as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThanOrEqual(1.0, $v);
        }
    }

    public function test_identical_posts_all_score_the_platform_midpoint(): void
    {
        $posts = [
            $this->metric('threads', 10, 1),
            $this->metric('threads', 10, 1),
            $this->metric('threads', 10, 1),
        ];

        foreach (OptimizerAgent::normalisedPostValues($posts) as $v) {
            $this->assertEqualsWithDelta(0.5, $v, 0.0001);
        }
    }

    // ─── 2. Normalisation is WITHIN platform, not across ────────────────────

    public function test_a_tiktok_post_is_scored_against_tiktok_not_against_facebook(): void
    {
        // TikTok's seed floor (~110 views) must not make every TikTok post look
        // like a winner next to Facebook's 1-impression reality.
        $posts = [
            $this->metric('tiktok', 110, 2),    // 0: median TikTok post
            $this->metric('tiktok', 115, 3),    // 1: better TikTok post
            $this->metric('facebook', 1, 0),    // 2: median Facebook post
            $this->metric('facebook', 2, 1),    // 3: better Facebook post
        ];

        $values = OptimizerAgent::normalisedPostValues($posts);

        // The better post on each platform scores identically — each is judged
        // against its own platform's distribution.
        $this->assertEqualsWithDelta($values[1], $values[3], 0.0001);
        $this->assertEqualsWithDelta($values[0], $values[2], 0.0001);
    }

    // ─── 3. Dimension mix is per-post, so volume cannot buy a win ───────────

    public function test_dimension_mix_uses_mean_value_so_volume_does_not_dominate(): void
    {
        // 12 mediocre carousels vs 3 excellent reels, same platform.
        $posts = [];
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('instagram', 10, 0, 'educational', 'carousel');
        }
        for ($i = 0; $i < 3; $i++) {
            $posts[] = $this->metric('instagram', 90, 5, 'behind_the_scenes', 'reel');
        }

        $values = OptimizerAgent::normalisedPostValues($posts);
        $mix = OptimizerAgent::meanValueByDimension($posts, $values, 'format');

        $this->assertGreaterThan(
            $mix['carousel'],
            $mix['reel'],
            'Reels outperform per post, so reel must lead the mix despite 4x fewer posts.',
        );
    }

    public function test_dimension_mix_is_a_normalised_distribution(): void
    {
        $posts = [
            $this->metric('instagram', 10, 1, 'educational', 'carousel'),
            $this->metric('instagram', 90, 5, 'community', 'reel'),
            $this->metric('instagram', 40, 2, 'promotional', 'single_image'),
        ];

        $values = OptimizerAgent::normalisedPostValues($posts);
        $mix = OptimizerAgent::meanValueByDimension($posts, $values, 'pillar');

        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_posts_with_a_null_dimension_are_skipped_not_bucketed_as_empty(): void
    {
        $posts = [
            $this->metric('instagram', 10, 1, null, null),
            $this->metric('instagram', 90, 5, 'community', 'reel'),
        ];

        $values = OptimizerAgent::normalisedPostValues($posts);
        $mix = OptimizerAgent::meanValueByDimension($posts, $values, 'pillar');

        $this->assertArrayNotHasKey('', $mix);
        $this->assertSame(['community'], array_keys($mix));
    }

    // ─── 4. Platform viability: stop spending cadence on dead surfaces ──────

    public function test_a_dead_surface_is_driven_to_a_token_weight(): void
    {
        // Facebook's real prod shape: many posts, ~1 impression each, no
        // engagement. Instagram alongside it is alive.
        $posts = [];
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('facebook', 1, 0);
        }
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('instagram', 13, 2);
        }

        $mix = OptimizerAgent::platformViability($posts);

        $this->assertLessThan(0.05, $mix['facebook'], 'A surface delivering ~1 impression per post must not keep an equal share of cadence.');
        $this->assertGreaterThan(0.8, $mix['instagram']);
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_a_dead_surface_keeps_a_nonzero_exploration_slot(): void
    {
        $posts = [];
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('facebook', 1, 0);
        }
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('instagram', 13, 2);
        }

        $mix = OptimizerAgent::platformViability($posts);

        // Never zero: the surface must be able to recover if the account is fixed.
        $this->assertGreaterThan(0.0, $mix['facebook']);
    }

    public function test_a_thin_new_surface_is_not_condemned_before_it_has_a_sample(): void
    {
        // Only 4 posts — below the verdict threshold, so no dead-surface call.
        $posts = [
            $this->metric('threads', 1, 0),
            $this->metric('threads', 1, 0),
            $this->metric('threads', 2, 0),
            $this->metric('threads', 1, 0),
        ];
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('instagram', 13, 2);
        }

        $mix = OptimizerAgent::platformViability($posts);

        $this->assertGreaterThan(0.05, $mix['threads'], 'A surface with too few posts to judge must not be written off.');
    }

    public function test_platform_mix_is_normalised_with_a_single_platform(): void
    {
        $posts = [
            $this->metric('linkedin', 37, 1),
            $this->metric('linkedin', 60, 2),
        ];

        $mix = OptimizerAgent::platformViability($posts);

        $this->assertSame(['linkedin'], array_keys($mix));
        $this->assertEqualsWithDelta(1.0, $mix['linkedin'], 0.0001);
    }

    public function test_empty_input_yields_empty_mixes_not_a_divide_by_zero(): void
    {
        $this->assertSame([], OptimizerAgent::normalisedPostValues([]));
        $this->assertSame([], OptimizerAgent::platformViability([]));
        $this->assertSame([], OptimizerAgent::meanValueByDimension([], [], 'pillar'));
    }

    // ─── 5. Collapse detection: a surface that STOPPED delivering ──────────
    //
    // A window median is backward-looking. TikTok's real prod shape on
    // 2026-07-31: ~93-116 views per post all month, then every post from 07-23
    // onward stuck at 0-4 (old posts unaffected, all posts published fine —
    // i.e. new-upload suppression at the platform's end). The 30-day median was
    // still ~102, so a median-only test called the surface healthy and would
    // have pushed MORE of August's cadence at a restricted account.

    /** @return array<int,array<string,mixed>> */
    private function series(string $platform, array $impressions, int $engagement = 1): array
    {
        $out = [];
        foreach (array_values($impressions) as $i => $imp) {
            $out[] = [
                'platform' => $platform,
                'impressions' => $imp,
                'engagement' => $imp > 5 ? $engagement : 0,
                'pillar' => null,
                'format' => null,
                // Ascending, one per day — chronological order is what makes
                // "recent" meaningful.
                'published_at' => sprintf('2026-07-%02d 09:00:00', $i + 1),
            ];
        }

        return $out;
    }

    public function test_a_surface_whose_recent_posts_collapsed_is_flagged_even_though_its_median_looks_fine(): void
    {
        // TikTok's real trajectory: ten healthy posts, then three at ~0.
        $posts = $this->series('tiktok', [93, 112, 102, 115, 111, 113, 116, 96, 96, 111, 1, 1, 4]);

        $health = OptimizerAgent::surfaceHealth($posts);

        $this->assertSame('collapsed', $health['tiktok']['verdict']);
        // The window median on its own still looks perfectly healthy.
        $this->assertGreaterThan(90, $health['tiktok']['median_impressions']);
    }

    public function test_a_collapsed_surface_loses_its_share_of_cadence(): void
    {
        $posts = array_merge(
            $this->series('tiktok', [93, 112, 102, 115, 111, 113, 116, 96, 96, 111, 1, 1, 4]),
            $this->series('instagram', [13, 11, 15, 12, 14, 13, 16, 12, 13, 15, 12, 14]),
        );

        $mix = OptimizerAgent::platformViability($posts);

        $this->assertLessThan(0.05, $mix['tiktok'], 'A suppressed account must not attract more of the month.');
        $this->assertGreaterThan(0.0, $mix['tiktok'], 'but must keep a slot so recovery is detected');
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_normal_week_to_week_variance_is_not_called_a_collapse(): void
    {
        // Instagram's real July: noisy but healthy, no suppression.
        $posts = $this->series('instagram', [17, 6, 13, 67, 7, 3, 61, 36, 53, 12, 40, 28]);

        $health = OptimizerAgent::surfaceHealth($posts);

        $this->assertSame('live', $health['instagram']['verdict']);
    }

    public function test_an_already_tiny_surface_is_called_dead_not_collapsed(): void
    {
        // Facebook never delivered — there is no height to fall from.
        $posts = $this->series('facebook', [1, 2, 1, 0, 3, 1, 2, 1, 1, 0, 2, 1]);

        $health = OptimizerAgent::surfaceHealth($posts);

        $this->assertSame('dead', $health['facebook']['verdict']);
    }

    public function test_collapse_needs_enough_posts_to_judge(): void
    {
        // Three healthy then two bad is not yet evidence of suppression.
        $posts = $this->series('threads', [40, 35, 38, 1, 2]);

        $health = OptimizerAgent::surfaceHealth($posts);

        $this->assertSame('live', $health['threads']['verdict']);
    }

    // ─── 6. Surface health is reported, not silently applied ───────────────

    public function test_dead_surfaces_are_named_for_the_operator(): void
    {
        $posts = [];
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('facebook', 1, 0);
        }
        for ($i = 0; $i < 12; $i++) {
            $posts[] = $this->metric('instagram', 13, 2);
        }

        $health = OptimizerAgent::surfaceHealth($posts);

        $this->assertSame('dead', $health['facebook']['verdict']);
        $this->assertSame('live', $health['instagram']['verdict']);
        $this->assertSame(12, $health['facebook']['posts']);
        $this->assertSame(0, $health['facebook']['engagement']);
    }
}
