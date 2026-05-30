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
}
