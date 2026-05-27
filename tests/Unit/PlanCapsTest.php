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
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth', 'aiVideosThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $soloPostCap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $soloVideoCap = (int) config('billing.plans.solo.caps.max_ai_videos_per_month');

        $ws->method('publishedPostsThisMonth')->willReturn($soloPostCap + 100);
        $ws->method('aiVideosThisMonth')->willReturn(2);

        $svc = new PlanCaps();
        $this->assertFalse($svc->canPublishMorePosts($ws), 'posts capped');
        $this->assertTrue($svc->canGenerateMoreAiVideos($ws), 'video still allowed independently');
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
