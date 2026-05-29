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
 * The old prices were set before per-customer Blotato cost ($29-$97/mo) was
 * factored in. At the old prices Solo lost ~RM 88/mo per customer, Studio cleared
 * RM ~30 at best, Agency was a coin-flip. New prices target ~50% gross margin
 * at typical use + ~30% at ceiling. Existing subscribers keep their old price
 * (Stripe price_id is locked at signup; stripe_prices table is keyed by tier
 * but new tiers get new price_ids on first checkout). Only NEW signups see
 * the new numbers.
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
 * 15s clip (8s base + 7s extend) ≈ $4.00 (≈ RM 18.80 at FX 4.7). 15s is opt-in
 * on every tier (not the silent default), so a customer CAN run their whole
 * allowance at 15s. To keep margin healthy under that worst case, video is
 * now bounded on THREE windows — per-month, per-week, AND per-day — so a burst
 * can't drain the month in two days. Realistic blended use lands ~68-79% gross
 * margin; the all-15s worst case is contained by the weekly + daily limits and
 * the per-tier USD breaker. Monthly caps: Solo 8, Studio 24, Agency 96.
 *
 * Per-tier `fal_*_daily_cap_usd` are now ACTUALLY enforced: DesignerAgent and
 * VideoAgent read them via PlanCaps (previously they read the global
 * services.fal.* value and these per-tier numbers were dead config). The
 * global services.fal.* values remain the fallback for workspace-less /
 * internal calls.
 */
return [
    'plans' => [
        'solo' => [
            'name'        => 'Solo',
            'price_myr'   => 549,
            // No free trial in v1. Each workspace requires a dedicated paid
            // Blotato account (~$29-$97/mo USD) that HQ provisions manually
            // — a free trial would mean EIAAW eats that cost for every
            // signup, including non-converters. Customers are charged on
            // checkout completion; access is gated until they pay.
            'trial_days'  => 0,
            'description' => '1 brand · 60 posts/mo · all 6 agents · audit log',
            'features'    => '1 brand, 60 posts/mo, 6 specialised agents, hard compliance gate, full audit log',
            'caps' => [
                // What the customer sees as the soft limit on /agency/billing.
                'max_brands' => 1,
                // Hard cap. Posts past this defer to next period (auto-release
                // 1st of month at workspace TZ). Soft warning email at 80%.
                'max_published_posts_per_month' => 60,
                // AI video generations — bounded on three windows (month/week/
                // day) so a burst of 15s clips ($4 each) can't drain the budget
                // in a couple of days. Hard fail past any window with an
                // "upgrade for more videos" notification (no defer — video cost
                // is incurred at generation, not publish). The day/week limits
                // SPREAD usage; the month limit is the real allowance.
                'max_ai_videos_per_month' => 8,
                'max_ai_videos_per_week' => 3,
                'max_ai_videos_per_day' => 1,
                // Per-tier daily FAL spend breakers (USD). ENFORCED via PlanCaps
                // by DesignerAgent/VideoAgent (a hard backstop against a
                // runaway-loop bug, independent of the count caps above).
                'fal_image_daily_cap_usd' => 1.50,
                'fal_video_daily_cap_usd' => 4.00,
            ],
        ],
        'studio' => [
            'name'        => 'Studio',
            'price_myr'   => 1099,
            'trial_days'  => 0,
            'description' => '3 brands · 300 posts/mo · white-label included',
            'features'    => '3 brands, 300 posts/mo, white-label client portal, all 6 agents',
            'caps' => [
                'max_brands' => 3,
                'max_published_posts_per_month' => 300,
                'max_ai_videos_per_month' => 24,
                'max_ai_videos_per_week' => 8,
                'max_ai_videos_per_day' => 3,
                'fal_image_daily_cap_usd' => 4.50,
                'fal_video_daily_cap_usd' => 12.00,
            ],
        ],
        'agency' => [
            'name'        => 'Agency',
            'price_myr'   => 3499,
            'trial_days'  => 0,
            'description' => '12 brands · unlimited posts · per-client guardrails',
            'features'    => '12 brands, unlimited posts, per-client guardrail isolation, priority support',
            'caps' => [
                'max_brands' => 12,
                // "Unlimited" in marketing copy = 1,500/mo hard cap (50/day average).
                // No realistic agency workload hits this; sits there to catch
                // abuse / runaway automation bugs.
                'max_published_posts_per_month' => 1500,
                'max_ai_videos_per_month' => 96,
                'max_ai_videos_per_week' => 32,
                'max_ai_videos_per_day' => 11,
                'fal_image_daily_cap_usd' => 6.00,
                'fal_video_daily_cap_usd' => 44.00,
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
            'caps' => [
                'max_brands' => PHP_INT_MAX,
                'max_published_posts_per_month' => PHP_INT_MAX,
                'max_ai_videos_per_month' => PHP_INT_MAX,
                'max_ai_videos_per_week' => PHP_INT_MAX,
                'max_ai_videos_per_day' => PHP_INT_MAX,
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
