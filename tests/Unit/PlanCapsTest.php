<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\Billing\PlanCaps;
use Tests\TestCase;

/**
 * PlanCaps — the source of truth for "what is this workspace allowed
 * to do this month". The caps numbers themselves live in config/billing.php
 * and can be tuned without a redeploy; these tests just lock the contract
 * that PlanCaps reads from config and never inlines defaults.
 *
 * Counter behaviour (publishedPostsThisMonth, aiVideosThisMonth,
 * activeBrandsCount) is tested separately in CapEnforcementTest where we
 * need a real DB. Here we mock the workspace + read pure config.
 */
class PlanCapsTest extends TestCase
{
    public function test_caps_for_solo_match_config(): void
    {
        $ws = new Workspace();
        $ws->plan = 'solo';

        $caps = (new PlanCaps())->capsFor($ws);

        $this->assertSame(config('billing.plans.solo.caps.max_brands'), $caps['max_brands']);
        $this->assertSame(config('billing.plans.solo.caps.max_published_posts_per_month'), $caps['max_published_posts_per_month']);
        $this->assertSame(config('billing.plans.solo.caps.max_ai_videos_per_month'), $caps['max_ai_videos_per_month']);
    }

    public function test_caps_for_studio_are_higher_than_solo(): void
    {
        $solo = new Workspace(); $solo->plan = 'solo';
        $studio = new Workspace(); $studio->plan = 'studio';

        $svc = new PlanCaps();
        $soloCaps = $svc->capsFor($solo);
        $studioCaps = $svc->capsFor($studio);

        $this->assertGreaterThan($soloCaps['max_brands'], $studioCaps['max_brands']);
        $this->assertGreaterThan($soloCaps['max_published_posts_per_month'], $studioCaps['max_published_posts_per_month']);
        $this->assertGreaterThan($soloCaps['max_ai_videos_per_month'], $studioCaps['max_ai_videos_per_month']);
    }

    public function test_caps_for_agency_are_higher_than_studio(): void
    {
        $studio = new Workspace(); $studio->plan = 'studio';
        $agency = new Workspace(); $agency->plan = 'agency';

        $svc = new PlanCaps();
        $studioCaps = $svc->capsFor($studio);
        $agencyCaps = $svc->capsFor($agency);

        $this->assertGreaterThan($studioCaps['max_brands'], $agencyCaps['max_brands']);
        $this->assertGreaterThan($studioCaps['max_published_posts_per_month'], $agencyCaps['max_published_posts_per_month']);
        $this->assertGreaterThan($studioCaps['max_ai_videos_per_month'], $agencyCaps['max_ai_videos_per_month']);
    }

    public function test_eiaaw_internal_has_effectively_unlimited_caps(): void
    {
        $ws = new Workspace();
        $ws->plan = 'eiaaw_internal';

        $caps = (new PlanCaps())->capsFor($ws);

        // PHP_INT_MAX is what config sets — anything that high is "no cap"
        // for all practical purposes.
        $this->assertGreaterThan(1_000_000, $caps['max_brands']);
        $this->assertGreaterThan(1_000_000, $caps['max_published_posts_per_month']);
        $this->assertGreaterThan(1_000_000, $caps['max_ai_videos_per_month']);
    }

    public function test_unknown_plan_falls_back_to_solo_caps_not_unlimited(): void
    {
        // Defensive: if a workspace ends up on an unknown plan (data corruption,
        // misconfigured Stripe sync, etc.), PlanCaps should NOT return
        // PHP_INT_MAX — that would be expensive-for-us. It should fall back
        // to the most restrictive tier (Solo).
        $ws = new Workspace();
        $ws->plan = 'totally_made_up_plan';

        $caps = (new PlanCaps())->capsFor($ws);
        $soloCaps = config('billing.plans.solo.caps');

        $this->assertSame($soloCaps['max_brands'], $caps['max_brands']);
        $this->assertSame($soloCaps['max_published_posts_per_month'], $caps['max_published_posts_per_month']);
    }

    public function test_is_near_post_cap_at_80_percent(): void
    {
        // Stub a workspace whose publishedPostsThisMonth returns 80% of the
        // Solo cap. isNearPostCap should fire.
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn((int) floor($soloPostCap * 0.8));

        $this->assertTrue((new PlanCaps())->isNearPostCap($ws));
    }

    public function test_is_near_post_cap_false_at_50_percent(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn((int) floor($soloPostCap * 0.5));

        $this->assertFalse((new PlanCaps())->isNearPostCap($ws));
    }

    public function test_can_publish_more_posts_true_below_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $ws->method('publishedPostsThisMonth')->willReturn(10);

        $this->assertTrue((new PlanCaps())->canPublishMorePosts($ws));
    }

    public function test_can_publish_more_posts_false_at_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn($soloPostCap);

        $this->assertFalse((new PlanCaps())->canPublishMorePosts($ws));
    }

    public function test_can_publish_more_posts_false_above_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn($soloPostCap + 5);

        $this->assertFalse((new PlanCaps())->canPublishMorePosts($ws));
    }

    public function test_can_generate_more_ai_videos_gates_on_video_count_not_post_count(): void
    {
        // A workspace at posts cap but with low video count should still
        // be allowed to generate video. Symmetric: at video cap but low
        // post count, should be blocked on video.
        /** @var Workspace $ws */
        $ws = \Mockery::mock(Workspace::class)->makePartial();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');

        $ws->shouldReceive('publishedPostsThisMonth')->andReturn($soloPostCap + 100);
        // Comfortably under all three video windows (Solo: 1/3/8).
        $ws->shouldReceive('aiVideosThisMonth')->andReturn(2);
        $ws->shouldReceive('aiVideosThisWeek')->andReturn(1);
        $ws->shouldReceive('aiVideosToday')->andReturn(0);

        $svc = new PlanCaps();
        $this->assertFalse($svc->canPublishMorePosts($ws), 'posts capped');
        $this->assertTrue($svc->canGenerateMoreAiVideos($ws), 'video still allowed independently');
    }

    /**
     * Build a Workspace test double with the three video-window counters
     * stubbed. Uses a Mockery partial: the counters are overridden but the real
     * Eloquent attribute machinery (so `->plan` resolves correctly) is kept —
     * PHPUnit's getMockBuilder double does not route the `plan` attribute the
     * same way, which silently fell back to Solo caps.
     *
     * @return Workspace
     */
    private function workspaceWithVideoCounts(string $plan, int $today, int $week, int $month): Workspace
    {
        /** @var Workspace $ws */
        $ws = \Mockery::mock(Workspace::class)->makePartial();
        $ws->plan = $plan;
        $ws->shouldReceive('aiVideosToday')->andReturn($today);
        $ws->shouldReceive('aiVideosThisWeek')->andReturn($week);
        $ws->shouldReceive('aiVideosThisMonth')->andReturn($month);

        return $ws;
    }

    public function test_video_allowed_when_under_all_three_windows(): void
    {
        $ws = $this->workspaceWithVideoCounts('agency', today: 1, week: 5, month: 20);
        $status = (new PlanCaps())->videoCapStatus($ws);

        $this->assertTrue($status['ok']);
        $this->assertNull($status['window']);
        $this->assertTrue((new PlanCaps())->canGenerateMoreAiVideos($ws));
    }

    public function test_daily_video_window_blocks_first_and_reports_soonest_remedy(): void
    {
        // Solo day cap = 1. At 1 today, blocked on the DAY window even though
        // week/month have room — the daily limit spreads usage.
        $dayCap = (int) config('billing.plans.solo.caps.max_ai_videos_per_day');
        $ws = $this->workspaceWithVideoCounts('solo', today: $dayCap, week: 1, month: 1);

        $status = (new PlanCaps())->videoCapStatus($ws);
        $this->assertFalse($status['ok']);
        $this->assertSame('day', $status['window']);
        $this->assertStringContainsStringIgnoringCase('day', (string) $status['message']);
        $this->assertFalse((new PlanCaps())->canGenerateMoreAiVideos($ws));
    }

    public function test_weekly_video_window_blocks_when_day_has_room(): void
    {
        // Under the day cap (0 today) but at the week cap → blocked on WEEK.
        $weekCap = (int) config('billing.plans.studio.caps.max_ai_videos_per_week');
        $ws = $this->workspaceWithVideoCounts('studio', today: 0, week: $weekCap, month: $weekCap);

        $status = (new PlanCaps())->videoCapStatus($ws);
        $this->assertFalse($status['ok']);
        $this->assertSame('week', $status['window']);
    }

    public function test_monthly_video_window_blocks_when_day_and_week_have_room(): void
    {
        // Under day + week but at the month cap → blocked on MONTH, and the
        // message points to upgrade (a monthly cap doesn't clear "tomorrow").
        $monthCap = (int) config('billing.plans.agency.caps.max_ai_videos_per_month');
        $ws = $this->workspaceWithVideoCounts('agency', today: 0, week: 0, month: $monthCap);

        $status = (new PlanCaps())->videoCapStatus($ws);
        $this->assertFalse($status['ok']);
        $this->assertSame('month', $status['window']);
        $this->assertStringContainsStringIgnoringCase('upgrade', (string) $status['message']);
    }

    public function test_video_windows_widen_across_tiers(): void
    {
        $svc = new PlanCaps();
        $solo = new Workspace(); $solo->plan = 'solo';
        $studio = new Workspace(); $studio->plan = 'studio';
        $agency = new Workspace(); $agency->plan = 'agency';

        foreach (['max_ai_videos_per_day', 'max_ai_videos_per_week', 'max_ai_videos_per_month'] as $k) {
            $this->assertGreaterThan($svc->capsFor($solo)[$k], $svc->capsFor($studio)[$k], "studio > solo on {$k}");
            $this->assertGreaterThan($svc->capsFor($studio)[$k], $svc->capsFor($agency)[$k], "agency > studio on {$k}");
        }
    }

    public function test_per_tier_fal_video_breaker_widens_across_tiers(): void
    {
        // The breaker that DesignerAgent/VideoAgent now actually read.
        $svc = new PlanCaps();
        $solo = new Workspace(); $solo->plan = 'solo';
        $studio = new Workspace(); $studio->plan = 'studio';
        $agency = new Workspace(); $agency->plan = 'agency';

        $this->assertGreaterThan($svc->capsFor($solo)['fal_video_daily_cap_usd'], $svc->capsFor($studio)['fal_video_daily_cap_usd']);
        $this->assertGreaterThan($svc->capsFor($studio)['fal_video_daily_cap_usd'], $svc->capsFor($agency)['fal_video_daily_cap_usd']);
    }

    public function test_can_add_brand_true_when_under_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['activeBrandsCount'])
            ->getMock();
        $ws->plan = 'studio'; // cap = 3
        $ws->method('activeBrandsCount')->willReturn(2);

        $this->assertTrue((new PlanCaps())->canAddBrand($ws));
    }

    public function test_can_add_brand_false_when_at_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['activeBrandsCount'])
            ->getMock();
        $ws->plan = 'studio'; // cap = 3
        $ws->method('activeBrandsCount')->willReturn(3);

        $this->assertFalse((new PlanCaps())->canAddBrand($ws));
    }
}
