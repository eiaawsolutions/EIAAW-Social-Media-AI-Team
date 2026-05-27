<?php

namespace App\Services\Billing;

use App\Models\Workspace;

/**
 * Plan caps — single source of truth for "what is this workspace allowed
 * to do this month" enforcement.
 *
 * Reads from config('billing.plans.{plan}.caps') so the operator can tune
 * limits without redeploying (caps in config, prices in Stripe). Every
 * enforcement site (Brand create, SubmitScheduledPost, VideoAgent) routes
 * through this — never duplicates the cap value inline.
 *
 * Why a service and not Workspace methods: keeps cap logic out of the model
 * (which already carries Cashier billable + Blotato + publishing-pause
 * state) and lets tests mock cap config without touching DB.
 */
class PlanCaps
{
    /** Sentinel returned by canPublishMorePosts when at cap. */
    public const RESULT_OK = 'ok';
    public const RESULT_CAP_REACHED = 'cap_reached';
    public const RESULT_PLAN_UNKNOWN = 'plan_unknown';

    /**
     * @return array{
     *   max_brands:int,
     *   max_published_posts_per_month:int,
     *   max_ai_videos_per_month:int,
     *   fal_image_daily_cap_usd:float,
     *   fal_video_daily_cap_usd:float,
     * }
     */
    public function capsFor(Workspace $workspace): array
    {
        $plan = (string) ($workspace->plan ?? 'solo');
        $caps = config("billing.plans.{$plan}.caps");

        // Defensive default — if a workspace ends up on an unknown plan we
        // fall back to Solo caps rather than PHP_INT_MAX (which would be
        // the safe-for-user but expensive-for-us default).
        if (! is_array($caps)) {
            $caps = config('billing.plans.solo.caps', [
                'max_brands' => 1,
                'max_published_posts_per_month' => 60,
                'max_ai_videos_per_month' => 20,
                'fal_image_daily_cap_usd' => 1.50,
                'fal_video_daily_cap_usd' => 5.00,
            ]);
        }

        return [
            'max_brands' => (int) $caps['max_brands'],
            'max_published_posts_per_month' => (int) $caps['max_published_posts_per_month'],
            'max_ai_videos_per_month' => (int) $caps['max_ai_videos_per_month'],
            'fal_image_daily_cap_usd' => (float) $caps['fal_image_daily_cap_usd'],
            'fal_video_daily_cap_usd' => (float) $caps['fal_video_daily_cap_usd'],
        ];
    }

    /**
     * True when the workspace can add another brand. Counts non-archived
     * brands only — archived brands don't count toward the cap, so a
     * customer who archives an old brand frees up the slot.
     */
    public function canAddBrand(Workspace $workspace): bool
    {
        $caps = $this->capsFor($workspace);
        return $workspace->activeBrandsCount() < $caps['max_brands'];
    }

    /**
     * True when this workspace can publish another post in the current
     * calendar month (workspace timezone). When false, SubmitScheduledPost
     * defers the post to status='queued_next_period' for auto-release on
     * the 1st of the next month.
     */
    public function canPublishMorePosts(Workspace $workspace): bool
    {
        $caps = $this->capsFor($workspace);
        return $workspace->publishedPostsThisMonth() < $caps['max_published_posts_per_month'];
    }

    /**
     * True when VideoAgent can generate another AI video this month. When
     * false, VideoAgent hard-fails with a clear "upgrade for more videos"
     * message — unlike posts, the cost is incurred at generation time, so
     * deferring would just push the bill to next month.
     */
    public function canGenerateMoreAiVideos(Workspace $workspace): bool
    {
        $caps = $this->capsFor($workspace);
        return $workspace->aiVideosThisMonth() < $caps['max_ai_videos_per_month'];
    }

    /**
     * Soft-warning threshold — 80% of cap. Used by the rollup mailer to
     * send "you've used 80% of your monthly posts" 1x per period so the
     * user has time to upgrade before hitting the hard cap.
     */
    public function isNearPostCap(Workspace $workspace): bool
    {
        $caps = $this->capsFor($workspace);
        $cap = $caps['max_published_posts_per_month'];
        if ($cap <= 0) return false;
        return $workspace->publishedPostsThisMonth() >= (int) floor($cap * 0.8);
    }
}
