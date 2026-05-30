<?php

/*
|--------------------------------------------------------------------------
| Cost register — operator-set running costs of the SMT product
|--------------------------------------------------------------------------
|
| This file is the single source of truth for the costs that are NOT
| measured automatically by the app. It feeds the HQ Cost Monitor
| (App\Services\Monitoring\CostMonitor + the /admin/cost-monitor page).
|
| TRUTHFULNESS CONTRACT (locked): the app NEVER fabricates a metric. So we
| split costs into two kinds:
|
|   1. MEASURED costs — variable AI spend (Anthropic, FAL/Veo, embeddings,
|      TTS). These are real, per-call rows in the `ai_costs` table. The Cost
|      Monitor reads them directly; nothing in THIS file affects them.
|
|   2. OPERATOR-SET costs — fixed infra (Railway, Resend, Cloudflare, the
|      domain) and the per-workspace Blotato seat. The app cannot know these;
|      only the operator does. They are declared HERE, clearly as assumptions,
|      and surfaced in the UI with an "operator-set" tag so they are never
|      mistaken for a measured number. Edit the real figures below.
|
| Every value is in MYR unless the key ends in `_usd`. USD values are
| converted with `fx.usd_to_myr` so the whole monitor reports one currency.
|
| Update cadence: whenever a vendor invoice changes, edit the number here and
| redeploy. (A future v1.1 may move these into an editable DB table so they
| can change without a deploy — see the Cost Monitor delivery notes.)
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | FX rate
    |--------------------------------------------------------------------------
    | USD → MYR. Matches the inline `* 4.7` used across the AI cost writers
    | (LlmGateway, FalAiClient, EmbeddingService, FalTtsClient) so the monitor
    | reconciles with the ai_costs.cost_myr column. If you move the AI writers
    | to a live daily rate later, point them and this at the same source.
    */
    'fx' => [
        'usd_to_myr' => (float) env('COST_FX_USD_TO_MYR', 4.7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixed monthly infrastructure costs (OPERATOR-SET — edit these)
    |--------------------------------------------------------------------------
    | The flat monthly bills that exist whether you have 1 paying customer or
    | 100. These do NOT scale with signups. Values are MYR/month unless the
    | key ends in `_usd`.
    |
    | Seeded to 0 deliberately: a 0 is an honest "not yet entered" — the UI
    | flags any 0 line so the profit number is never quietly overstated by a
    | forgotten cost. Replace each 0 with the real recurring figure from the
    | vendor invoice. Add or remove lines freely; the monitor sums whatever is
    | here. `label` drives the UI row; `amount_myr` or `amount_usd` is the cost.
    */
    'fixed' => [
        'railway' => [
            'label' => 'Railway (app + worker + Postgres + Redis)',
            'amount_usd' => (float) env('COST_RAILWAY_USD', 0),
        ],
        'resend' => [
            'label' => 'Resend (transactional email)',
            'amount_usd' => (float) env('COST_RESEND_USD', 0),
        ],
        'cloudflare' => [
            'label' => 'Cloudflare (DNS, CDN, R2)',
            'amount_usd' => (float) env('COST_CLOUDFLARE_USD', 0),
        ],
        'anthropic_base' => [
            'label' => 'Anthropic / API base plan (if any flat fee)',
            'amount_usd' => (float) env('COST_ANTHROPIC_BASE_USD', 0),
        ],
        'domain' => [
            'label' => 'Domain (eiaawsolutions.com, amortised/mo)',
            'amount_myr' => (float) env('COST_DOMAIN_MYR', 0),
        ],
        'infisical' => [
            'label' => 'Infisical (secrets management)',
            'amount_usd' => (float) env('COST_INFISICAL_USD', 0),
        ],
        'other' => [
            'label' => 'Other fixed tooling / subscriptions',
            'amount_myr' => (float) env('COST_OTHER_FIXED_MYR', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-workspace variable cost — Blotato seat (OPERATOR-SET rate × live count)
    |--------------------------------------------------------------------------
    | Every PAID workspace gets a dedicated Blotato account that HQ provisions
    | manually (~$29–$97/mo USD per the billing config notes). This cost scales
    | 1:1 with live signups, which is exactly the "live update based on live
    | signups" the monitor must reflect.
    |
    | The RATE here is operator-set (one flat USD figure). The COUNT is REAL —
    | the monitor counts workspaces that actually have a Blotato handle wired
    | (`blotato_api_key_handle` set). So total Blotato cost = rate × live count,
    | and it moves the moment a new workspace is provisioned.
    |
    | If you later record each workspace's actual Blotato plan ($29 vs $97),
    | store it per-workspace and switch the monitor to sum actuals (documented
    | follow-up). For now a single blended rate is the truthful approximation,
    | and the UI labels it as such.
    */
    'per_workspace' => [
        'blotato' => [
            'label' => 'Blotato seat (per paid workspace)',
            'amount_usd' => (float) env('COST_BLOTATO_PER_WORKSPACE_USD', 29),
        ],
    ],

];
