<?php

/**
 * Plan catalog. Source-of-truth for tier definitions used by the public signup
 * flow and the in-panel /agency/billing upgrade flow.
 *
 * Stripe Products and Prices are LAZY-CREATED on first checkout per plan via
 * App\Services\StripePriceCache. The created price_id is cached in the
 * stripe_prices table so subsequent checkouts reuse it. No STRIPE_PRICE_*
 * env vars exist anymore.
 *
 * Mirrors the pattern at:
 *   c:/laragon/www/Sales marketing agent/src/routes/billing.js (PLANS const)
 */
return [
    'plans' => [
        'solo' => [
            'name'        => 'Solo',
            'price_myr'   => 99,
            'trial_days'  => 14,
            'description' => '1 brand · 60 posts/mo · all 6 agents · audit log',
            'features'    => '1 brand, 60 posts/mo, 6 specialised agents, hard compliance gate, full audit log',
        ],
        'studio' => [
            'name'        => 'Studio',
            'price_myr'   => 299,
            'trial_days'  => 14,
            'description' => '3 brands · 300 posts/mo · white-label included',
            'features'    => '3 brands, 300 posts/mo, white-label client portal, all 6 agents',
        ],
        'agency' => [
            'name'        => 'Agency',
            'price_myr'   => 799,
            'trial_days'  => 14,
            'description' => '12 brands · unlimited posts · per-client guardrails',
            'features'    => '12 brands, unlimited posts, per-client guardrail isolation, priority support',
        ],
    ],

    'currency' => 'myr',

    'default_interval' => 'month',
];
