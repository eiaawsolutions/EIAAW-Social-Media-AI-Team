<?php

namespace Tests\Unit;

use App\Services\Metrics\MetricoolMetricsCollector;
use Tests\TestCase;

/**
 * MetricoolMetricsCollector — field mapping + URL join.
 *
 * DB-free. The fixtures here are the REAL Metricool post shapes captured live
 * on 2026-05-30 (memory metricool-field-map): Instagram and TikTok first-post
 * field sets, verbatim. We assert the collector maps them onto our post_metrics
 * columns per the verified spec and honours the Truthfulness Contract (NULL for
 * genuinely-absent counters, never a fabricated zero).
 */
class MetricoolMetricsCollectorTest extends TestCase
{
    private function collector(): MetricoolMetricsCollector
    {
        // Field mapping + URL join don't touch the client; pass null.
        return new MetricoolMetricsCollector(null);
    }

    /** Real Instagram post field set returned by Metricool (live 2026-05-30). */
    private function instagramPost(): array
    {
        return [
            'postId' => 'ig_1',
            'type' => 'REEL',
            'url' => 'https://www.instagram.com/p/ABC123/',
            'likes' => 120,
            'comments' => 14,
            'shares' => 7,
            'interactions' => 145,
            'reach' => 3000,
            'saved' => 9,
            'impressionsTotal' => 5000,
            'views' => 4200,
        ];
    }

    /** Real TikTok post field set returned by Metricool (live 2026-05-30). */
    private function tiktokPost(): array
    {
        return [
            'videoId' => 'tt_1',
            'shareUrl' => 'https://www.tiktok.com/@eiaaw/video/999/',
            'likeCount' => 200,
            'commentCount' => 18,
            'shareCount' => 25,
            'viewCount' => 12000,
            'engagement' => 243,
        ];
    }

    public function test_instagram_maps_impressions_to_impressions_total_not_views(): void
    {
        $m = $this->collector()->normalise('instagram', $this->instagramPost());

        $this->assertSame('metrics', $m['status']);
        $this->assertSame('metricool', $m['source']);
        // impressions MUST come from impressionsTotal (5000), NOT views (4200).
        $this->assertSame(5000, $m['impressions']);
        $this->assertSame(3000, $m['reach']);
        $this->assertSame(120, $m['likes']);
        $this->assertSame(14, $m['comments']);
        $this->assertSame(7, $m['shares']);
        $this->assertSame(9, $m['saves']);
        $this->assertSame(4200, $m['video_views']);
    }

    public function test_instagram_engagement_rate_is_derived_from_interactions_over_impressions(): void
    {
        $m = $this->collector()->normalise('instagram', $this->instagramPost());

        // interactions(145) / impressionsTotal(5000) = 0.029
        $this->assertSame(0.029, $m['engagement_rate']);
    }

    public function test_instagram_absent_counters_are_null_not_zero(): void
    {
        $m = $this->collector()->normalise('instagram', $this->instagramPost());

        // IG analytics carry no profile_visits / url_clicks → NULL, never 0.
        $this->assertNull($m['profile_visits']);
        $this->assertNull($m['url_clicks']);
    }

    public function test_tiktok_maps_view_count_to_impressions(): void
    {
        $m = $this->collector()->normalise('tiktok', $this->tiktokPost());

        $this->assertSame(12000, $m['impressions']);
        $this->assertSame(200, $m['likes']);
        $this->assertSame(18, $m['comments']);
        $this->assertSame(25, $m['shares']);
    }

    public function test_tiktok_reach_is_null_platform_limit_not_fabricated(): void
    {
        $m = $this->collector()->normalise('tiktok', $this->tiktokPost());

        // TikTok's API has no reach field — the contract REQUIRES NULL here,
        // not a 0 and not an alias of views.
        $this->assertNull($m['reach']);
    }

    public function test_engagement_rate_null_when_no_impressions(): void
    {
        $m = $this->collector()->normalise('instagram', [
            'url' => 'https://x/y',
            'likes' => 3,
        ]);

        // No impressions → cannot compute a rate → NULL (no divide-by-zero,
        // no fabricated 0.0).
        $this->assertNull($m['engagement_rate']);
        $this->assertNull($m['impressions']);
        $this->assertSame(3, $m['likes']);
    }

    // ─── Robust post-matching (the LinkedIn/TikTok/YouTube metrics gap) ──────
    //
    // Root cause found live 2026-06-02: the Metricool API returns real metrics
    // for EVERY network, but the old exact-string matchByUrl never matched
    // LinkedIn/TikTok/YouTube posts because Metricool's reported URL differs
    // from ours by: a `www.` prefix, a `?utm_…` query suffix (TikTok), a
    // different URN representation (LinkedIn share↔ugcPost), or a different
    // URL field name entirely (YouTube uses `watchUrl`, FB uses `link`). The
    // fix matches on a per-platform CANONICAL post id extracted from both
    // sides, plus Metricool's own id fields (videoId/postId). The fixtures
    // below are the REAL shapes captured from prod blogId 6322515 (2026-06-02).

    /** instagram: shortcode from /p/<code>/ ignoring www + trailing slash. */
    public function test_match_instagram_by_shortcode_ignores_www_and_slash(): void
    {
        $posts = [['url' => 'https://www.instagram.com/p/DYz0lSFCH00/', 'likes' => 5]];
        $ours = 'https://instagram.com/p/DYz0lSFCH00';
        $this->assertNotNull($this->collector()->matchPost('instagram', $posts, $ours, null));
    }

    /** tiktok: Metricool appends ?utm_campaign=… — match on the numeric video id. */
    public function test_match_tiktok_ignores_utm_query_suffix(): void
    {
        $posts = [[
            'videoId' => '7644248763753696519',
            'shareUrl' => 'https://www.tiktok.com/@eiaawsolutions/video/7644248763753696519?utm_campaign=tt4d_open_api&utm_source=xyz',
            'viewCount' => 60,
        ]];
        $ours = 'https://www.tiktok.com/@eiaawsolutions/video/7644248763753696519';
        $this->assertNotNull($this->collector()->matchPost('tiktok', $posts, $ours, null));
    }

    /** youtube: Metricool puts the URL in `watchUrl` + exposes `videoId`. */
    public function test_match_youtube_by_video_id_via_watchurl_field(): void
    {
        $posts = [[
            'videoId' => 'u7SovfEHkEM',
            'watchUrl' => 'https://www.youtube.com/watch?v=u7SovfEHkEM',
            'views' => 13,
        ]];
        $ours = 'https://www.youtube.com/watch?v=u7SovfEHkEM';
        $this->assertNotNull($this->collector()->matchPost('youtube', $posts, $ours, null));
    }

    /** linkedin: same ugcPost URN, different www/case — numeric activity id matches. */
    public function test_match_linkedin_by_numeric_activity_id(): void
    {
        $posts = [[
            'postId' => 'urn:li:ugcPost:7466340650083377152',
            'url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7466340650083377152',
            'impressions' => 16,
        ]];
        $ours = 'https://linkedin.com/feed/update/urn:li:ugcPost:7466340650083377152';
        $this->assertNotNull($this->collector()->matchPost('linkedin', $posts, $ours, null));
    }

    /**
     * linkedin: share↔ugcPost URN forms carry DIFFERENT numeric ids for the
     * same post — so a share-URL of ours genuinely cannot match an ugcPost the
     * API reports. This MUST return null (no false-positive cross-match), which
     * is the documented LinkedIn limitation, not a regression.
     */
    public function test_no_false_match_when_linkedin_urn_ids_differ(): void
    {
        $posts = [[
            'postId' => 'urn:li:ugcPost:7467171310805176320',
            'url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7467171310805176320',
            'impressions' => 16,
        ]];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:7456717032256958465';
        $this->assertNull($this->collector()->matchPost('linkedin', $posts, $ours, null));
    }

    /** threads: shortcode from /post/<code> via the `permalink` field. */
    public function test_match_threads_by_shortcode_via_permalink(): void
    {
        $posts = [[
            'shortCode' => 'DY8uzjbl7mG',
            'permalink' => 'https://www.threads.com/@eiaawsolutions/post/DY8uzjbl7mG',
            'views' => 8,
        ]];
        $ours = 'https://www.threads.com/@eiaawsolutions/post/DY8uzjbl7mG';
        $this->assertNotNull($this->collector()->matchPost('threads', $posts, $ours, null));
    }

    /** A non-matching post in the list must NOT be returned. */
    public function test_no_match_returns_null(): void
    {
        $posts = [['videoId' => '111', 'shareUrl' => 'https://www.tiktok.com/@x/video/111']];
        $ours = 'https://www.tiktok.com/@x/video/999';
        $this->assertNull($this->collector()->matchPost('tiktok', $posts, $ours, null));
    }

    // ─── Caption-text fallback (the LinkedIn share≠ugcPost recovery) ─────────
    //
    // LinkedIn stores `urn:li:share:<a>` at publish but Metricool analytics
    // reports `urn:li:ugcPost:<b>` — DIFFERENT numbers for the same post, so id
    // matching is impossible (above). The ONLY reliable join is the post
    // CAPTION: LinkedIn analytics carries it in `comment`, IG in `content`,
    // TikTok in `videoDescription`. matchPost() takes the caption as a 5th arg
    // and falls back to it when id/URL matching fails. Fixtures are the REAL
    // LinkedIn analytics shape captured from prod (2026-06-02).

    public function test_match_linkedin_by_caption_when_urn_ids_differ(): void
    {
        $caption = '"Run an entire organisation in one click" sounds like a replacement. It is not.';
        $posts = [[
            'postId' => 'urn:li:ugcPost:7467171310805176320',          // ugcPost
            'url' => 'https://www.linkedin.com/feed/update/urn:li:ugcPost:7467171310805176320',
            'comment' => $caption,                                     // <-- the caption field
            'impressions' => 16,
        ]];
        // Our stored URL is the share form (won't id-match); caption bridges.
        $ours = 'https://linkedin.com/feed/update/urn:li:share:7456717032256958465';
        $match = $this->collector()->matchPost('linkedin', $posts, $ours, null, $caption);
        $this->assertNotNull($match);
        $this->assertSame(16, $match['impressions']);
    }

    public function test_caption_fallback_matches_when_platform_appends_hashtags(): void
    {
        // The REAL Metricool case: the platform shows our full body then appends
        // the hashtag block. Our body is a clean PREFIX of the platform caption,
        // so they match on the common leading length.
        $ourCaption = 'Most AI rollouts skip the most important step and nobody notices';
        $posts = [['comment' => $ourCaption . ' #ai #automation #b2b', 'likes' => 3]];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:1';
        $this->assertNotNull(
            $this->collector()->matchPost('linkedin', $posts, $ours, null, $ourCaption)
        );
    }

    public function test_caption_fallback_does_not_match_on_a_shared_short_opener(): void
    {
        // Two posts in a series share a generic opener but diverge — must NOT
        // false-match (mid-string divergence within the comparable length).
        $ourCaption = 'Here is the thing about AI onboarding calls that nobody admits openly';
        $posts = [['comment' => 'Here is the thing about AI vendor demos that always goes wrong', 'likes' => 3]];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:1';
        $this->assertNull(
            $this->collector()->matchPost('linkedin', $posts, $ours, null, $ourCaption)
        );
    }

    public function test_caption_fallback_abstains_when_two_rows_match_ambiguously(): void
    {
        // If our caption prefix matches MORE than one row, abstain (return null)
        // rather than risk attributing the wrong post's metrics.
        $cap = 'A long enough distinctive caption opener about AI rollouts and pilots';
        $posts = [
            ['comment' => $cap . ' part one', 'likes' => 1],
            ['comment' => $cap . ' part two', 'likes' => 2],
        ];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:1';
        $this->assertNull(
            $this->collector()->matchPost('linkedin', $posts, $ours, null, $cap)
        );
    }

    public function test_caption_fallback_does_not_match_a_different_post(): void
    {
        $posts = [['comment' => 'Completely unrelated caption about something else entirely here', 'likes' => 9]];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:1';
        $this->assertNull(
            $this->collector()->matchPost('linkedin', $posts, $ours, null, 'Our caption is about AI onboarding calls')
        );
    }

    public function test_caption_fallback_ignored_when_too_short_to_be_distinctive(): void
    {
        // A 10-char caption is too generic to safely bridge on — must NOT match
        // (avoids false positives on boilerplate openers).
        $posts = [['comment' => 'Thank you so much everyone for the support today!!', 'likes' => 1]];
        $ours = 'https://linkedin.com/feed/update/urn:li:share:1';
        $this->assertNull(
            $this->collector()->matchPost('linkedin', $posts, $ours, null, 'Thank you')
        );
    }
}
