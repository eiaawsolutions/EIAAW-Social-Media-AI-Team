<?php

namespace App\Services\Billing;

use App\Exceptions\BrandCreationRefused;
use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

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
     *   max_ai_image_posts_per_month:int,
     *   max_published_posts_per_month:int,
     *   max_ai_videos_per_month:int,
     * }
     *
     * NOTE (2026-06-01): there is NO per-day USD FAL breaker here by design.
     * Generation is bound ONLY by the monthly volume caps above — a customer may
     * spend their whole monthly allowance in one day. The old
     * fal_*_daily_cap_usd keys were removed because they acted as a hidden daily
     * usage cap (Solo $4/day = one 15s Veo clip), not a runaway backstop. Do not
     * reintroduce a daily/weekly throttle. Guarded by
     * PlanCapsTest::test_no_daily_usd_fal_breaker_in_config_or_caps.
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

        // Per-key fallbacks to Solo so a tier missing a key degrades safely
        // instead of throwing on an undefined index.
        $solo = config('billing.plans.solo.caps', []);
        $val = static fn (string $k, $default) => (int) ($caps[$k] ?? $solo[$k] ?? $default);

        return [
            'max_brands' => $val('max_brands', 1),
            'max_ai_image_posts_per_month' => $val('max_ai_image_posts_per_month', 60),
            'max_published_posts_per_month' => $val('max_published_posts_per_month', 65),
            'max_ai_videos_per_month' => $val('max_ai_videos_per_month', 5),
        ];
    }

    /**
     * True when the workspace can add another brand. Counts non-archived
     * brands only — archived brands don't count toward the cap, so a
     * customer who archives an old brand frees up the slot.
     *
     * NOTE: this is a cheap, race-prone READ used for UX (hide the create
     * button, show an upgrade nudge). It MUST NOT be the only thing standing
     * between a customer and an over-cap insert — two rapid creates can both
     * read "under cap" and both insert. The authoritative, atomic enforcement
     * is createBrandOrFail() below.
     */
    public function canAddBrand(Workspace $workspace): bool
    {
        $caps = $this->capsFor($workspace);
        return $workspace->activeBrandsCount() < $caps['max_brands'];
    }

    /**
     * Atomically create a brand for the workspace, or throw BrandCreationRefused.
     *
     * This is the SINGLE authoritative brand-create path. It exists because the
     * read-then-write canAddBrand() check leaked under a stale-relation /
     * double-submit race and let a solo workspace (max_brands=1) end up with TWO
     * brands, splitting onboarding work across them (the brand voice landed on
     * one record, the Metricool connections on the other — see
     * [[onboarding-split-brain-brands]]).
     *
     * The fix is to make the count-check and the insert happen INSIDE one
     * transaction, behind a row-level lock on the workspace, so two concurrent
     * creates serialise: the first commits the brand, the second re-reads the
     * now-incremented count under the lock and is refused. Same lock also guards
     * the duplicate-name check, so two identical names can't both slip through.
     *
     * Archived brands don't count toward the cap and don't block a name reuse —
     * consistent with activeBrandsCount() and the archive-frees-a-slot model.
     *
     * @param  array<string,mixed>  $attributes  validated brand attributes (name, slug, …);
     *                                            workspace_id is forced to $workspace->id.
     * @throws BrandCreationRefused  when at cap or a same-named active brand exists.
     */
    public function createBrandOrFail(Workspace $workspace, array $attributes): Brand
    {
        $caps = $this->capsFor($workspace);
        $max = $caps['max_brands'];
        $plan = (string) ($workspace->plan ?? 'solo');
        $name = trim((string) ($attributes['name'] ?? ''));

        return DB::transaction(function () use ($workspace, $attributes, $max, $plan, $name) {
            // Serialise concurrent creates for this workspace. Locking the
            // workspace row (not the brands) gives a single, deadlock-free
            // gate that every create for this tenant must pass through.
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();

            // Re-read counts/names UNDER the lock — this is the authoritative
            // state, immune to the relation cache the UI check may have read.
            $activeBrands = Brand::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('archived_at');

            if ((clone $activeBrands)->count() >= $max) {
                throw BrandCreationRefused::capReached($max, $plan);
            }

            if ($name !== '' && (clone $activeBrands)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists()
            ) {
                throw BrandCreationRefused::duplicateName($name);
            }

            $attributes['workspace_id'] = $workspace->id;

            return Brand::create($attributes);
        });
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
     * True when VideoAgent can generate another AI video this month. The video
     * allowance is a single MONTHLY cap — the customer self-paces within the
     * month (no weekly/daily throttle). Cost is incurred at generation, so we
     * gate before the FAL call, not after.
     */
    public function canGenerateMoreAiVideos(Workspace $workspace): bool
    {
        return $this->videoCapStatus($workspace)['ok'] === true;
    }

    /**
     * Whether the monthly video allowance is exhausted. Returns ok=true when the
     * workspace may generate, otherwise a human-facing reason so VideoAgent can
     * tell the customer the limit and the remedy (upgrade or wait for reset).
     *
     * @return array{ok:bool, window:?string, used:int, limit:int, message:?string}
     */
    public function videoCapStatus(Workspace $workspace): array
    {
        $caps = $this->capsFor($workspace);
        $plan = ucfirst((string) ($workspace->plan ?? 'solo'));

        $used = $workspace->aiVideosThisMonth();
        $limit = $caps['max_ai_videos_per_month'];

        if ($used >= $limit) {
            return [
                'ok' => false,
                'window' => 'month',
                'used' => $used,
                'limit' => $limit,
                'message' => sprintf(
                    '%s plan monthly AI-video allowance reached (%d/%d). Resets on the 1st, '
                    .'or upgrade at /agency/billing for a higher video allowance.',
                    $plan, $used, $limit,
                ),
            ];
        }

        return ['ok' => true, 'window' => null, 'used' => $used, 'limit' => $limit, 'message' => null];
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

    /**
     * Remaining published-post allowance for the current calendar month
     * (never negative). The content autopilot uses this to bound how many
     * new drafts it generates per workspace — there's no point drafting 200
     * posts for a workspace capped at 65/month, and over-generating just
     * burns FAL image/video budget on drafts that will sit unpublished or
     * defer to next period. Counts already-published posts this month against
     * the plan cap; in-flight queued posts are deliberately NOT subtracted
     * here (the autopilot subtracts those itself so the two concerns stay
     * separable and testable).
     */
    public function remainingPostAllowance(Workspace $workspace): int
    {
        $caps = $this->capsFor($workspace);
        $cap = (int) $caps['max_published_posts_per_month'];
        if ($cap <= 0) return 0;
        return max(0, $cap - $workspace->publishedPostsThisMonth());
    }
}
