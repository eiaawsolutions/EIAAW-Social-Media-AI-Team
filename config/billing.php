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
 * Veo 3 clip). Driven by the Veo 3 video COGS (~RM 18.80 per 15s clip) — the
 * video allowance is now small and explicit (5/15/60) so video can't sink the
 * tier, while image posts stay generous (60/180/720) since a Nano Banana still
 * is only ~RM 0.18. Realistic blended gross margin lands ~66-73% across tiers.
 * Existing subscribers keep their old price (Stripe price_id is locked at
 * signup); only NEW signups see the new numbers.
 *
 * POST-CAP MODEL: `max_published_posts_per_month` is the TOTAL publish ceiling
 * = image allowance + video allowance, so a video post never eats into the
 * image budget. `max_ai_image_posts_per_month` and `max_ai_videos_per_month`
 * are the marketed split (drive the pricing-card copy); video is independently
 * hard-gated by the month/week/day video windows below.
 *
 * PLATFORMS: every tier supports the same set — Facebook, Instagram, Threads,
 * TikTok, YouTube, LinkedIn (the Blotato + Veo video-capable set). Platforms
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
 * VIDEO COST REBASE 2026-05-29: video moved from FAL Wan (~$0.50/clip) to
 * Google Veo 3 Fast + Veo 3.1 extend — a 6s clip ≈ $0.90, 8s ≈ $1.20, and a
 * 15s clip (8s base + 7s extend) ≈ $4.00 (≈ RM 18.80 at FX 4.7). The video
 * allowance is a single MONTHLY cap (Solo 5 / Studio 15 / Agency 60); the
 * customer self-paces within the month — no weekly or daily throttle. The
 * allowance is small enough that even all-15s use can't sink the tier
 * (RM 94/282/1128 of video against RM 688/1688/6888 prices). Realistic blended
 * gross margin ~66-73%.
 *
 * Per-tier `fal_*_daily_cap_usd` are ACTUALLY enforced (DesignerAgent and
 * VideoAgent read them via PlanCaps; previously they read the global
 * services.fal.* and these per-tier numbers were dead config). This USD breaker
 * is a runaway-loop BACKSTOP, not a usage cap — it only trips on a generation
 * bug, never in normal monthly-paced use. The global services.fal.* values
 * remain the fallback for workspace-less / internal calls.
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
            'description' => '1 brand · 60 image posts + 5 video posts/mo · all 6 agents · audit log',
            'features'    => '1 brand, 60 AI image posts/mo, 5 AI 15-sec video posts/mo, 6 specialised agents, hard compliance gate, full audit log',
            // Supported publishing platforms (same set on every tier).
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                // What the customer sees as the soft limit on /agency/billing.
                'max_brands' => 1,
                // Marketed media split (drives pricing-card copy).
                'max_ai_image_posts_per_month' => 60,
                // TOTAL publish ceiling = image + video, so a video post never
                // eats the image budget. Posts past this defer to next period
                // (auto-release 1st of month at workspace TZ). Warning at 80%.
                'max_published_posts_per_month' => 65,
                // AI video generations (each a 15s Veo clip ≈ RM 18.80) —
                // a single MONTHLY allowance; the customer self-paces within the
                // month (no weekly/daily throttle). Hard fail past the month cap
                // (no defer — video cost is at generation, not publish). The
                // per-tier USD breaker below is a separate runaway-loop backstop,
                // NOT a usage cap.
                'max_ai_videos_per_month' => 5,
                // Per-tier daily FAL spend breakers (USD). ENFORCED via PlanCaps
                // by DesignerAgent/VideoAgent (a hard backstop against a
                // runaway-loop bug, independent of the count caps above).
                'fal_image_daily_cap_usd' => 1.50,
                'fal_video_daily_cap_usd' => 4.00,
            ],
        ],
        'studio' => [
            'name'        => 'Studio',
            'price_myr'   => 1688,
            'trial_days'  => 0,
            'description' => '3 brands · 180 image posts + 15 video posts/mo · all 6 agents',
            'features'    => '3 brands, 180 AI image posts/mo, 15 AI 15-sec video posts/mo, all 6 agents, hard compliance gate, full audit log',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                'max_brands' => 3,
                'max_ai_image_posts_per_month' => 180,
                'max_published_posts_per_month' => 195, // 180 image + 15 video
                'max_ai_videos_per_month' => 15,
                'fal_image_daily_cap_usd' => 4.50,
                'fal_video_daily_cap_usd' => 8.00,
            ],
        ],
        'agency' => [
            'name'        => 'Agency',
            'price_myr'   => 6888,
            'trial_days'  => 0,
            'description' => '12 brands · 720 image posts + 60 video posts/mo · per-client guardrails',
            'features'    => '12 brands, 720 AI image posts/mo, 60 AI 15-sec video posts/mo, per-client guardrail isolation, priority support',
            'platforms'   => ['facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'linkedin'],
            'caps' => [
                'max_brands' => 12,
                'max_ai_image_posts_per_month' => 720,
                'max_published_posts_per_month' => 780, // 720 image + 60 video
                'max_ai_videos_per_month' => 60,
                'fal_image_daily_cap_usd' => 6.00,
                'fal_video_daily_cap_usd' => 28.00,
            ],
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
                'fal_image_daily_cap_usd' => 50.00,
                'fal_video_daily_cap_usd' => 100.00,
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
