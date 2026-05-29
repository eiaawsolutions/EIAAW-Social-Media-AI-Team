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
     *   max_ai_videos_per_week:int,
     *   max_ai_videos_per_day:int,
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
            $caps = config('billing.plans.solo.caps', []);
        }

        // Per-key fallbacks to Solo so a tier missing a newer key (e.g. the
        // 2026-05-29 weekly/daily video windows) degrades safely instead of
        // throwing on an undefined index.
        $solo = config('billing.plans.solo.caps', []);
        $val = static fn (string $k, $default) => (int) ($caps[$k] ?? $solo[$k] ?? $default);
        $valF = static fn (string $k, $default) => (float) ($caps[$k] ?? $solo[$k] ?? $default);

        return [
            'max_brands' => $val('max_brands', 1),
            'max_published_posts_per_month' => $val('max_published_posts_per_month', 60),
            'max_ai_videos_per_month' => $val('max_ai_videos_per_month', 8),
            'max_ai_videos_per_week' => $val('max_ai_videos_per_week', 3),
            'max_ai_videos_per_day' => $val('max_ai_videos_per_day', 1),
            'fal_image_daily_cap_usd' => $valF('fal_image_daily_cap_usd', 1.50),
            'fal_video_daily_cap_usd' => $valF('fal_video_daily_cap_usd', 4.00),
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
     * True when VideoAgent can generate another AI video right now — i.e. the
     * workspace is under its per-DAY, per-WEEK, AND per-MONTH video limits. Any
     * one window being full blocks generation. When false, VideoAgent hard-
     * fails with a clear message naming the window that's full (cost is incurred
     * at generation, so we gate before the FAL call, not after).
     *
     * Three windows because a single 15s clip costs ~$4 and 15s is opt-in on
     * every tier: the month cap is the real allowance; the week/day caps SPREAD
     * usage so a burst can't drain the month (and the bill) in a couple of days.
     */
    public function canGenerateMoreAiVideos(Workspace $workspace): bool
    {
        return $this->videoCapStatus($workspace)['ok'] === true;
    }

    /**
     * Which video window (if any) is full. Returns self::RESULT_OK when the
     * workspace may generate, otherwise a human-facing reason naming the window
     * and its limit so VideoAgent can tell the customer exactly what to do
     * (wait until tomorrow / next week, or upgrade).
     *
     * @return array{ok:bool, window:?string, used:int, limit:int, message:?string}
     */
    public function videoCapStatus(Workspace $workspace): array
    {
        $caps = $this->capsFor($workspace);
        $plan = ucfirst((string) ($workspace->plan ?? 'solo'));

        // Order: day → week → month. Report the SHORTEST window that's full so
        // the message tells the customer the soonest thing they can do (a daily
        // limit clears tomorrow; a monthly one needs an upgrade or next period).
        $windows = [
            ['key' => 'day',   'used' => $workspace->aiVideosToday(),     'limit' => $caps['max_ai_videos_per_day'],   'clears' => 'tomorrow'],
            ['key' => 'week',  'used' => $workspace->aiVideosThisWeek(),  'limit' => $caps['max_ai_videos_per_week'],  'clears' => 'next week'],
            ['key' => 'month', 'used' => $workspace->aiVideosThisMonth(), 'limit' => $caps['max_ai_videos_per_month'], 'clears' => 'next month'],
        ];

        foreach ($windows as $w) {
            if ($w['used'] >= $w['limit']) {
                $upgrade = $w['key'] === 'month'
                    ? ' Upgrade at /agency/billing for a higher monthly video allowance.'
                    : ' Resets '.$w['clears'].', or upgrade at /agency/billing for higher limits.';

                return [
                    'ok' => false,
                    'window' => $w['key'],
                    'used' => $w['used'],
                    'limit' => $w['limit'],
                    'message' => sprintf(
                        '%s plan AI-video %s limit reached (%d/%d).%s',
                        $plan, $w['key'], $w['used'], $w['limit'], $upgrade,
                    ),
                ];
            }
        }

        return ['ok' => true, 'window' => null, 'used' => 0, 'limit' => 0, 'message' => null];
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
