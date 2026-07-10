<?php

/**
 * Plan catalog. Source-of-truth for tier definitions used by the public signup
 * flow, the in-panel /agency/billing upgrade flow, and PlanCaps enforcement.
 *
 * Stripe Products and Prices are LAZY-CREATED on first checkout per plan via
 * App\Services\StripePriceCache. The created price_id is cached in the
 * stripe_prices table so subsequent checkouts reuse it. No STRIPE_PRICE_*
 * env vars exist anymore.
 *
 * Mirrors the pattern at:
 *   c:/laragon/www/Sales marketing agent/src/routes/billing.js (PLANS const)
 *
 * Pricing rebase 2026-05-27: prices raised from RM 99/299/799 to RM 549/1099/3499.
 *
 * Pricing rebase 2026-05-29 (final v1): RM 549/1099/3499 → RM 688/1688/6888,
 * and the media allowance split into IMAGE posts vs VIDEO posts (each a 15s
 * clip). The video allowance is small and explicit (5/15/60) so video can't
 * sink the tier, while image posts stay generous (60/180/720) since a Nano
 * Banana still is only ~RM 0.18. Realistic blended gross margin lands ~66-73%.
 * Existing subscribers keep their old price (Stripe price_id is locked at
 * signup); only NEW signups see the new numbers.
 *
 * Video COGS update 2026-07: switched Veo 3 (~RM 18.80 / 15s clip, 8s base +
 * extend) → ByteDance Seedance 2.0 Fast (~$0.2419/s @ 720p, native audio
 * bundled, single 15s call, no extend ≈ $3.63 ≈ RM 17/clip). Slightly cheaper
 * and simpler; the allowances/caps below are unchanged and margin improves a
 * touch. See VideoAgent SEEDANCE_* constants for the live per-second rate.
 *
 * Cap rebase 2026-06-04 (allowance tightening + Enterprise tier): PRICES UNCHANGED
 * (RM 688/1688/6888) but allowances cut to lift margin —
 *   Solo   1 brand  · 25 image · 4 video   (was 60 image · 5 video)
 *   Studio 3 brands · 75 image · 12 video  (was 180 image · 15 video)
 *   Agency 10 brands· 300 image· 48 video  (was 12 brands · 720 image · 60 video)
 * and a fourth tier, ENTERPRISE, was added — a "Talk to us" lead tier with NO
 * Stripe checkout and NO fixed caps (caps=null = unlimited until an operator
 * provisions a bespoke workspace). Enterprise is NOT in SignupController::ALLOWED_PLANS,
 * so it can never be self-serve checked-out; its card routes to /enterprise (a
 * dedicated enquiry form), not /signup/{plan}.
 *
 * GRANDFATHERING (locked decision, 2026-06-04): these LOWER caps must NOT silently
 * strip allowance from workspaces that signed up under the old numbers (e.g. the
 * live Bear Hug Solo workspace had 60 image / 5 video). PlanCaps therefore reads a
 * per-workspace cap SNAPSHOT stored at signup (workspaces.settings.plan_caps_snapshot)
 * and only falls back to THIS live config when no snapshot exists. New signups get
 * a snapshot of these new numbers; existing workspaces were backfilled with their
 * old numbers. So editing a cap here changes the DEFAULT for brand-new signups only —
 * never an existing subscriber. See App\Services\Billing\PlanCaps::capsFor() and
 * App\Services\Billing\SignupProvisioner. To intentionally re-grant a customer the
 * new caps, clear their snapshot (workspace:resnapshot-caps --reset).
 *
 * POST-CAP MODEL: `max_published_posts_per_month` is the TOTAL publish ceiling
 * = image allowance + video allowance, so a video post never eats into the
 * image budget. `max_ai_image_posts_per_month` and `max_ai_videos_per_month`
 * are the marketed split (drive the pricing-card copy); video is independently
 * hard-gated by the month/week/day video windows below.
 *
 * PLATFORMS: every tier supports the same set — Facebook, Instagram, Threads,
 * TikTok, YouTube, LinkedIn (the publish + video-capable set). Platforms
 * are not tier-gated; the list is documented per tier for marketing clarity.
 *
 * Cap philosophy: marketed limits are GENEROUS (60/300/unlimited posts, all
 * agents, etc) so the product feels abundant. Hard caps sit underneath as
 * safety rails against the runaway outlier user. They exist to protect margin
 * from abuse, not to throttle normal use — typical users never hit them. When
 * they do, posts queue for next period and auto-publish on reset (no lost
 * content); videos hard-fail with an upgrade nudge. See
 * App\Services\Billing\PlanCaps for the enforcement layer.
 *
 * VIDEO COST: Wan (~$0.50/clip, 2026-05) → Veo 3 Fast + extend (15s ≈ $4.00 ≈
 * RM 18.80, 2026-05-29) → ByteDance Seedance 2.0 Fast (2026-07): ~$0.2419/s @
 * 720p with native audio bundled and NO extend, so a 15s clip ≈ $3.63 ≈ RM 17.
 * The video allowance is a single MONTHLY cap (Solo 5 / Studio 15 / Agency 60);
 * the customer self-paces within the month — no weekly or daily throttle. The
 * allowance is small enough that even all-15s use can't sink the tier
 * (≈ RM 85/255/1020 of video against RM 688/1688/6888 prices). Realistic blended
 * gross margin ~67-74%.
 *
 * There is intentionally NO per-day USD FAL breaker (removed 2026-06-01).
 * Image/video generation is bound ONLY by the monthly volume caps below — the
 * customer self-paces within the month and may spend the whole allowance in one
 * day. The old per-tier `fal_*_daily_cap_usd` keys acted as a hidden daily usage
 * cap (Solo $4/day = one 15s Veo clip) and were removed; do not reintroduce.
 * See [[no-daily-fal-cap]].
 */
return [
    'plans' => [
        'solo' => [
            'name'        => 'Solo',
            'price_myr'   => 688,
            // No free trial in v1. Each workspace requires a dedicated paid
            // Blotato account (~$29-$97/mo USD) that HQ provisions manually
            // — a free trial would mean EIAAW eats that cost for every
            // signup, including non-converters. Customers are charged on
            // checkout completion; access is gated until they pay.
            'trial_days'  => 0,
            'description' => '1 brand · 25 image posts + 4 video posts/mo · all 6 agents · audit log',
            'features'    => '1 brand, 25 AI image posts/mo, 4 AI 15-sec video posts/mo, 6 specialised agents, hard compliance gate, full audit log',
            // Supported publishing platforms (same set on every tier).
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                // What the customer sees as the soft limit on /agency/billing.
                'max_brands' => 1,
                // Marketed media split (drives pricing-card copy).
                'max_ai_image_posts_per_month' => 25,
                // TOTAL publish ceiling = image + video, so a video post never
                // eats the image budget. Posts past this defer to next period
                // (auto-release 1st of month at workspace TZ). Warning at 80%.
                'max_published_posts_per_month' => 29, // 25 image + 4 video
                // AI video generations (each a 15s Seedance 2.0 clip ≈ RM 17) —
                // a single MONTHLY allowance; the customer self-paces within the
                // month (no weekly/daily throttle). Hard fail past the month cap
                // (no defer — video cost is at generation, not publish).
                // NOTE (2026-06-01): there is intentionally NO per-day USD FAL
                // breaker — image/video are bound ONLY by these monthly volume
                // caps. The old fal_*_daily_cap_usd keys were a hidden daily usage
                // cap and were removed; do not reintroduce. See [[no-daily-fal-cap]]
                // and PlanCapsTest::test_no_daily_usd_fal_breaker_in_config_or_caps.
                'max_ai_videos_per_month' => 4,
            ],
        ],
        'studio' => [
            'name'        => 'Studio',
            'price_myr'   => 1688,
            'trial_days'  => 0,
            'description' => '3 brands · 75 image posts + 12 video posts/mo · all 6 agents',
            'features'    => '3 brands, 75 AI image posts/mo, 12 AI 15-sec video posts/mo, all 6 agents, hard compliance gate, full audit log',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                'max_brands' => 3,
                'max_ai_image_posts_per_month' => 75,
                'max_published_posts_per_month' => 87, // 75 image + 12 video
                'max_ai_videos_per_month' => 12,
            ],
        ],
        'agency' => [
            'name'        => 'Agency',
            'price_myr'   => 6888,
            'trial_days'  => 0,
            'description' => '10 brands · 300 image posts + 48 video posts/mo · per-client guardrails',
            'features'    => '10 brands, 300 AI image posts/mo, 48 AI 15-sec video posts/mo, per-client guardrail isolation, priority support',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                'max_brands' => 10,
                'max_ai_image_posts_per_month' => 300,
                'max_published_posts_per_month' => 348, // 300 image + 48 video
                'max_ai_videos_per_month' => 48,
            ],
        ],
        // ENTERPRISE — a "Talk to us" lead tier, NOT a self-serve checkout plan.
        //
        // Shown as the 4th pricing card on the landing + signup picker, but its
        // CTA is "Talk to us" → /enterprise (a dedicated enquiry form), never
        // /signup/enterprise. It is deliberately ABSENT from
        // SignupController::ALLOWED_PLANS, so BillingController::checkout() and
        // StripePriceCache will refuse it — there is no Stripe Product/Price for
        // Enterprise. Pricing and caps are bespoke, negotiated per deal, then an
        // operator provisions a workspace on plan='enterprise' with a custom cap
        // snapshot (workspaces.settings.plan_caps_snapshot).
        //
        // price_myr=0 / is_contact=true drive the card to render "Custom" + a
        // Talk-to-us button instead of a price + Subscribe button. caps=null
        // means "no fixed plan caps" — if a workspace is ever put on plan=
        // 'enterprise' WITHOUT a per-workspace snapshot, PlanCaps treats null as
        // unlimited (PHP_INT_MAX) rather than falling back to Solo, because an
        // Enterprise customer must never be silently throttled to Solo limits.
        'enterprise' => [
            'name'        => 'Enterprise',
            'price_myr'   => 0,        // bespoke — never charged via Stripe
            'is_contact'  => true,     // renders "Talk to us" instead of Subscribe
            'trial_days'  => 0,
            'description' => 'Custom brands · custom image + video allowances · per-client guardrails · priority support',
            'features'    => 'Unlimited brands (negotiated), custom AI image + video allowances, all 6 agents, per-client guardrail isolation, priority support, bespoke onboarding',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            // null = no fixed plan caps. A provisioned Enterprise workspace gets a
            // bespoke per-workspace snapshot; absent that, PlanCaps treats this as
            // unlimited (never Solo fallback) — see PlanCaps::capsFor().
            'caps' => null,
        ],

        // EIAAW internal workspace — no caps, no billing, no Stripe.
        // Used by HQ + dogfooding workspaces only.
        'eiaaw_internal' => [
            'name'        => 'EIAAW Internal',
            'price_myr'   => 0,
            'trial_days'  => 0,
            'description' => 'EIAAW internal — no caps',
            'features'    => 'unlimited',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                'max_brands' => PHP_INT_MAX,
                'max_ai_image_posts_per_month' => PHP_INT_MAX,
                'max_published_posts_per_month' => PHP_INT_MAX,
                'max_ai_videos_per_month' => PHP_INT_MAX,
            ],
        ],
    ],

    'currency' => 'myr',

    'default_interval' => 'month',

    /*
    |--------------------------------------------------------------------------
    | Annual pricing
    |--------------------------------------------------------------------------
    | Customers who pay annually save 2 months — multiplier 10× the monthly
    | price (= "12 months for the price of 10"). StripePriceCache reads this
    | when creating year-interval Prices so the saving is materialised in
    | Stripe, not just in the UI copy. Tunable per-tier later if we want
    | "Solo gets 2 months free, Agency gets 3" — for now uniform across tiers.
    */
    'annual_multiplier' => 10,

    /*
    |--------------------------------------------------------------------------
    | Tax (SST) — TAX-READY, NOT TAX-ACTIVE
    |--------------------------------------------------------------------------
    | Malaysia SST is 8% on digital services, BUT only after the seller
    | crosses RM 500,000 in MY-customer revenue per 12-month period and
    | registers with the Royal Malaysian Customs Department (RMCD). Below
    | that threshold a Malaysian seller has no authority to collect SST.
    |
    | EIAAW is currently below the threshold, so `enabled` stays false.
    | When/if we cross RM 500k:
    |   1. Register with RMCD, get SST registration number
    |   2. Populate `registration_number` below
    |   3. Flip `enabled` to true
    |   4. Existing Stripe Prices already have tax_behavior=exclusive so
    |      they continue to work — Stripe just starts adding 8% on top
    |      for MY customers from the moment the flag flips
    |   5. Invoice template will automatically include the SST line +
    |      registration number once `enabled` is true
    |
    | NOTE: Stripe Tax (automatic-tax) is NOT suitable for our case. Stripe
    | Tax's Malaysia support is for foreign sellers selling INTO Malaysia
    | ("Your business location: Not supported" per Stripe docs). We handle
    | SST application ourselves once registered.
    */
    'tax' => [
        'enabled' => env('SST_ENABLED', false),
        'rate' => (float) env('SST_RATE', 0.08), // 8% as of 2024-03-01
        'registration_number' => env('SST_REGISTRATION_NUMBER'),
        'authority' => 'Royal Malaysian Customs Department (RMCD)',
        // tax_behavior on Stripe Prices. 'exclusive' = price shown is
        // pre-tax, Stripe adds tax on top. We use exclusive so prices match
        // what the customer sees in marketing (RM 549 etc.) — and so when
        // we flip the flag on, existing Prices don't need recreation.
        'price_tax_behavior' => 'exclusive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer geography
    |--------------------------------------------------------------------------
    | v1 ships Malaysia-only. Restricts signup to MY billing addresses and
    | keeps the SST + currency story simple. International (especially EU/UK
    | for VAT compliance, SG for GST) opens a separate compliance surface.
    */
    'allowed_countries' => ['MY'],
];
