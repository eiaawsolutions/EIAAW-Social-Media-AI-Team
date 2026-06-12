<?php

namespace Tests\Unit;

use App\Agents\GrowthStrategistAgent;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure-function tests (no DB) for GrowthStrategistAgent's signal computers. Each
 * PostMetric is built in-memory with its scheduledPost→draft→calendarEntry chain
 * wired via setRelation, so the joins resolve without a database — the same
 * discipline as CompetitorStrategyBriefVerificationTest. These lock the
 * truthfulness contract: every signal is a real computed number.
 */
class GrowthSignalComputationTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $m   post_metrics attributes
     * @param  array<string,mixed>  $sp  scheduled_post attributes (published_at)
     * @param  array<string,mixed>  $pp  draft platform_payload (hook_pattern, cta)
     */
    private function metric(array $m, array $sp = [], array $pp = [], ?string $objective = null): PostMetric
    {
        $metric = new PostMetric($m);
        $metric->platform = $m['platform'] ?? 'instagram';
        // null-safe fields the model fills via attributes:
        foreach (['impressions', 'reach', 'likes', 'comments', 'shares', 'saves', 'url_clicks', 'profile_visits'] as $f) {
            $metric->{$f} = $m[$f] ?? null;
        }

        $entry = new CalendarEntry(['objective' => $objective]);
        $draft = new Draft(['platform_payload' => $pp]);
        $draft->setRelation('calendarEntry', $entry);

        $scheduledPost = new ScheduledPost();
        if (isset($sp['published_at'])) {
            $scheduledPost->published_at = Carbon::parse($sp['published_at']);
        }
        $scheduledPost->setRelation('draft', $draft);

        $metric->setRelation('scheduledPost', $scheduledPost);

        return $metric;
    }

    public function test_hook_performance_ranks_by_engagement_and_drops_sub_floor(): void
    {
        $rows = new Collection([
            // authority_insight: 2 strong posts
            $this->metric(['likes' => 100, 'impressions' => 1000], [], ['hook_pattern' => 'authority_insight']),
            $this->metric(['likes' => 90, 'impressions' => 900], [], ['hook_pattern' => 'authority_insight']),
            // story: 2 weak posts
            $this->metric(['likes' => 5, 'impressions' => 50], [], ['hook_pattern' => 'story']),
            $this->metric(['likes' => 4, 'impressions' => 40], [], ['hook_pattern' => 'story']),
            // contrarian: only 1 post → dropped by the sample floor
            $this->metric(['likes' => 200, 'impressions' => 2000], [], ['hook_pattern' => 'contrarian']),
        ]);

        $out = GrowthStrategistAgent::computeHookPerformance($rows, 2);

        $hooks = array_column($out, 'hook_pattern');
        $this->assertNotContains('contrarian', $hooks, 'single-sample hook must be dropped');
        $this->assertSame('authority_insight', $out[0]['hook_pattern'], 'highest avg engagement ranks first');
        $this->assertSame(2, $out[0]['sample_n']);
    }

    public function test_best_posting_times_picks_top_bucket_per_platform_from_published_at(): void
    {
        $rows = new Collection([
            // instagram Tue 08:00 — two strong posts
            $this->metric(['platform' => 'instagram', 'impressions' => 1000], ['published_at' => '2026-06-09 08:00:00']),
            $this->metric(['platform' => 'instagram', 'impressions' => 1100], ['published_at' => '2026-06-16 08:00:00']),
            // instagram Sat 22:00 — two weak posts
            $this->metric(['platform' => 'instagram', 'impressions' => 50], ['published_at' => '2026-06-13 22:00:00']),
            $this->metric(['platform' => 'instagram', 'impressions' => 40], ['published_at' => '2026-06-20 22:00:00']),
        ]);

        $out = GrowthStrategistAgent::computeBestPostingTimes($rows, 2);

        $this->assertArrayHasKey('instagram', $out);
        $top = $out['instagram'][0];
        $this->assertSame(2, $top['day_of_week'], 'Tuesday (dow=2) should win'); // 2026-06-09 is a Tuesday
        $this->assertSame(8, $top['hour']);
    }

    public function test_cta_lift_averages_over_readings_only_and_flags_signal(): void
    {
        $rows = new Collection([
            // with CTA: url_clicks readings 20, 30 → avg 25
            $this->metric(['url_clicks' => 20], [], ['cta' => 'Book a call']),
            $this->metric(['url_clicks' => 30], [], ['cta' => 'Book a call']),
            // a with-CTA post with NULL url_clicks must NOT drag the average down
            $this->metric(['url_clicks' => null], [], ['cta' => 'Book a call']),
            // without CTA: url_clicks readings 8, 12 → avg 10
            $this->metric(['url_clicks' => 8], [], []),
            $this->metric(['url_clicks' => 12], [], []),
        ]);

        $out = GrowthStrategistAgent::computeCtaLift($rows);

        $this->assertTrue($out['has_signal']);
        $this->assertSame(25.0, $out['with_cta']['avg_url_clicks']);
        $this->assertSame(10.0, $out['without_cta']['avg_url_clicks']);
        $this->assertSame(150.0, $out['lift_pct']); // (25-10)/10 * 100
    }

    public function test_cta_lift_has_no_signal_when_no_conversion_readings(): void
    {
        $rows = new Collection([
            $this->metric(['url_clicks' => null, 'likes' => 5], [], ['cta' => 'X']),
            $this->metric(['url_clicks' => null, 'likes' => 3], [], []),
        ]);

        $out = GrowthStrategistAgent::computeCtaLift($rows);

        $this->assertFalse($out['has_signal']);
        $this->assertNull($out['lift_pct']);
    }

    public function test_platform_focus_shares_sum_to_about_100(): void
    {
        $rows = new Collection([
            $this->metric(['platform' => 'instagram', 'reach' => 700]),
            $this->metric(['platform' => 'linkedin', 'reach' => 300]),
        ]);

        $out = GrowthStrategistAgent::computePlatformFocus($rows);

        $this->assertSame(70.0, $out['instagram']['reach_share_pct']);
        $this->assertSame(30.0, $out['linkedin']['reach_share_pct']);
        $this->assertEqualsWithDelta(100.0, $out['instagram']['reach_share_pct'] + $out['linkedin']['reach_share_pct'], 0.1);
    }

    public function test_objective_mix_biases_toward_click_driving_objective(): void
    {
        $rows = new Collection([
            // leads posts drove real clicks
            $this->metric(['url_clicks' => 50, 'likes' => 5], [], [], 'leads'),
            $this->metric(['url_clicks' => 40, 'likes' => 4], [], [], 'leads'),
            // awareness posts drove only light engagement
            $this->metric(['likes' => 3], [], [], 'awareness'),
        ]);

        $out = GrowthStrategistAgent::computeObjectiveMix($rows);

        // All 5 objectives present, sums to ~1, and leads outweighs awareness.
        $this->assertEqualsWithDelta(1.0, array_sum($out), 0.01);
        $this->assertGreaterThan($out['awareness'], $out['leads']);
    }

    public function test_classify_follower_velocity_includes_only_ok_networks(): void
    {
        $forBrand = [
            'followers' => [
                'networks' => [
                    ['network' => 'instagram', 'label' => 'Instagram', 'status' => 'ok', 'headline' => 1020, 'change' => 20],
                    ['network' => 'tiktok', 'label' => 'TikTok', 'status' => 'ok', 'headline' => 500, 'change' => -10],
                    ['network' => 'linkedin', 'label' => 'LinkedIn', 'status' => 'not_available', 'headline' => null, 'change' => null],
                ],
            ],
        ];

        $out = GrowthStrategistAgent::classifyFollowerVelocity($forBrand);

        $this->assertArrayHasKey('instagram', $out);
        $this->assertArrayHasKey('tiktok', $out);
        $this->assertArrayNotHasKey('linkedin', $out, 'not_available network must be excluded');
        $this->assertSame('accelerating', $out['instagram']['direction']); // +20 over ~1000 = +2%
        $this->assertSame('declining', $out['tiktok']['direction']);        // -10 over ~510 ≈ -2%
    }

    public function test_filter_objective_guidance_drops_invalid_hooks_and_objectives(): void
    {
        $guidance = [
            'leads' => ['hook_patterns' => ['authority_insight', 'made_up_hook'], 'cta_styles' => ['Book a call']],
            'not_an_objective' => ['hook_patterns' => ['story'], 'cta_styles' => []],
            'awareness' => ['hook_patterns' => ['totally_fake'], 'cta_styles' => []], // nothing valid → dropped
        ];

        $out = GrowthStrategistAgent::filterObjectiveGuidance($guidance);

        $this->assertArrayHasKey('leads', $out);
        $this->assertSame(['authority_insight'], $out['leads']['hook_patterns'], 'invalid hook dropped');
        $this->assertArrayNotHasKey('not_an_objective', $out, 'invalid objective dropped');
        $this->assertArrayNotHasKey('awareness', $out, 'objective with no valid content dropped');
    }
}
