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
        // Comfortably under the monthly video cap (Solo: 5).
        $ws->shouldReceive('aiVideosThisMonth')->andReturn(2);

        $svc = new PlanCaps();
        $this->assertFalse($svc->canPublishMorePosts($ws), 'posts capped');
        $this->assertTrue($svc->canGenerateMoreAiVideos($ws), 'video still allowed independently');
    }

    /**
     * Build a Workspace test double with the monthly video counter stubbed.
     * Uses a Mockery partial: the counter is overridden but the real Eloquent
     * attribute machinery (so `->plan` resolves correctly) is kept — PHPUnit's
     * getMockBuilder double does not route the `plan` attribute the same way,
     * which silently fell back to Solo caps.
     *
     * @return Workspace
     */
    private function workspaceWithVideoCount(string $plan, int $month): Workspace
    {
        /** @var Workspace $ws */
        $ws = \Mockery::mock(Workspace::class)->makePartial();
        $ws->plan = $plan;
        $ws->shouldReceive('aiVideosThisMonth')->andReturn($month);

        return $ws;
    }

    public function test_video_allowed_when_under_monthly_cap(): void
    {
        // Agency monthly cap = 60; 20 used → allowed (customer self-paces, no
        // weekly/daily throttle).
        $ws = $this->workspaceWithVideoCount('agency', month: 20);
        $status = (new PlanCaps())->videoCapStatus($ws);

        $this->assertTrue($status['ok']);
        $this->assertNull($status['window']);
        $this->assertTrue((new PlanCaps())->canGenerateMoreAiVideos($ws));
    }

    public function test_video_blocked_at_monthly_cap_with_upgrade_message(): void
    {
        $monthCap = (int) config('billing.plans.solo.caps.max_ai_videos_per_month');
        $ws = $this->workspaceWithVideoCount('solo', month: $monthCap);

        $status = (new PlanCaps())->videoCapStatus($ws);
        $this->assertFalse($status['ok']);
        $this->assertSame('month', $status['window']);
        $this->assertStringContainsStringIgnoringCase('upgrade', (string) $status['message']);
        $this->assertFalse((new PlanCaps())->canGenerateMoreAiVideos($ws));
    }

    public function test_video_allowed_one_below_monthly_cap(): void
    {
        // Boundary: at cap-1 the customer can still generate (self-paced).
        $monthCap = (int) config('billing.plans.studio.caps.max_ai_videos_per_month');
        $ws = $this->workspaceWithVideoCount('studio', month: max(0, $monthCap - 1));

        $this->assertTrue((new PlanCaps())->canGenerateMoreAiVideos($ws));
    }

    public function test_monthly_video_cap_widens_across_tiers(): void
    {
        $svc = new PlanCaps();
        $solo = new Workspace(); $solo->plan = 'solo';
        $studio = new Workspace(); $studio->plan = 'studio';
        $agency = new Workspace(); $agency->plan = 'agency';

        $this->assertGreaterThan($svc->capsFor($solo)['max_ai_videos_per_month'], $svc->capsFor($studio)['max_ai_videos_per_month']);
        $this->assertGreaterThan($svc->capsFor($studio)['max_ai_videos_per_month'], $svc->capsFor($agency)['max_ai_videos_per_month']);
    }

    public function test_total_post_ceiling_equals_image_plus_video_allowance(): void
    {
        // The post-cap model: max_published_posts_per_month = image + video so a
        // video post never eats the image budget.
        foreach (['solo', 'studio', 'agency'] as $plan) {
            $caps = config("billing.plans.{$plan}.caps");
            $this->assertSame(
                (int) $caps['max_ai_image_posts_per_month'] + (int) $caps['max_ai_videos_per_month'],
                (int) $caps['max_published_posts_per_month'],
                "{$plan} total post ceiling must equal image + video allowance",
            );
        }
    }

    public function test_video_caps_have_no_weekly_or_daily_windows(): void
    {
        // Decided 2026-05-29: customer self-paces within the month — no weekly
        // or daily throttle. Guard against either being reintroduced silently.
        foreach (['solo', 'studio', 'agency'] as $plan) {
            $caps = config("billing.plans.{$plan}.caps");
            $this->assertArrayNotHasKey('max_ai_videos_per_week', $caps, "{$plan} must not have a weekly video cap");
            $this->assertArrayNotHasKey('max_ai_videos_per_day', $caps, "{$plan} must not have a daily video cap");
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

    public function test_remaining_post_allowance_is_cap_minus_published(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $cap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn(10);

        $this->assertSame($cap - 10, (new PlanCaps())->remainingPostAllowance($ws));
    }

    public function test_remaining_post_allowance_never_negative_when_over_cap(): void
    {
        // The autopilot uses this to bound drafting; it must never go negative
        // (which would underflow the budget math into a huge positive after a
        // later subtraction). A workspace over its cap has zero allowance.
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $cap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn($cap + 25);

        $this->assertSame(0, (new PlanCaps())->remainingPostAllowance($ws));
    }

    public function test_remaining_post_allowance_zero_at_exact_cap(): void
    {
        $ws = $this->getMockBuilder(Workspace::class)
            ->onlyMethods(['publishedPostsThisMonth'])
            ->getMock();
        $ws->plan = 'solo';
        $cap = (int) config('billing.plans.solo.caps.max_published_posts_per_month');
        $ws->method('publishedPostsThisMonth')->willReturn($cap);

        $this->assertSame(0, (new PlanCaps())->remainingPostAllowance($ws));
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

    // ── Atomic createBrandOrFail() — the authoritative gate ─────────────────
    //
    // The locked-transaction behaviour itself needs a real DB (covered by the
    // source-inspection guard in BrandCreateAtomicityTest, since the suite runs
    // against prod). Here we lock the exception contract that the UI depends on.

    public function test_brand_creation_refused_cap_reached_carries_reason_and_plan(): void
    {
        $e = \App\Exceptions\BrandCreationRefused::capReached(1, 'solo');

        $this->assertTrue($e->isCapReached());
        $this->assertFalse($e->isDuplicateName());
        $this->assertStringContainsStringIgnoringCase('Solo', $e->getMessage());
        $this->assertStringContainsString('1 brand', $e->getMessage());
        // Singular when max is 1 — no stray "1 brands".
        $this->assertStringNotContainsString('1 brands', $e->getMessage());
    }

    public function test_brand_creation_refused_cap_reached_pluralises_above_one(): void
    {
        $e = \App\Exceptions\BrandCreationRefused::capReached(3, 'studio');
        $this->assertStringContainsString('3 brands', $e->getMessage());
    }

    public function test_brand_creation_refused_duplicate_name_carries_reason_and_name(): void
    {
        $e = \App\Exceptions\BrandCreationRefused::duplicateName('The Bear Hug Cafe');

        $this->assertTrue($e->isDuplicateName());
        $this->assertFalse($e->isCapReached());
        $this->assertStringContainsString('The Bear Hug Cafe', $e->getMessage());
    }
}
