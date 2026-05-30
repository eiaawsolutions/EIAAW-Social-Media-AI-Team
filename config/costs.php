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
    | Railway — live usage cost via the Railway GraphQL billing API (MEASURED)
    |--------------------------------------------------------------------------
    | When a Railway token is wired, App\Services\Monitoring\RailwayCostClient
    | pulls this project's current-cycle + estimated usage from
    | backboard.railway.com/graphql/v2 and converts resource quantities to USD
    | using the unit prices below, replacing the operator-set `fixed.railway`
    | line with a MEASURED one. If the token is missing or the call fails, the
    | monitor silently falls back to `fixed.railway` (COST_RAILWAY_USD) so the
    | page never breaks. This is why the Railway line is in BOTH places.
    |
    | TOKEN: an Account or Workspace token (NOT a project token — usage/`me`
    | data is workspace-scoped). Per the EIAAW deploy contract it lives in
    | Infisical and `token_handle` holds the `secret://...` reference; the
    | resolver turns it into the real value at runtime. Create the token at
    | https://railway.com/account/tokens (human action in the dashboard).
    |
    | Verified against the live schema 2026-05-30: estimatedUsage(projectId,
    | measurements) and usage(projectId, measurements, startDate, endDate)
    | each return rows of {measurement, (estimated)value} in resource units
    | (vCPU-minutes, GB-months, GB egress) — NOT dollars — so we price them
    | here. Unit prices are Railway's published rates (docs.railway.com
    | pricing); edit if Railway repricing changes them.
    */
    'railway' => [
        // Master switch. Off until a token is wired — until then the
        // operator-set fixed.railway line carries the cost.
        'enabled' => (bool) env('RAILWAY_COST_ENABLED', false),

        // secret://... handle resolved by InfisicalResolver (EIAAW contract).
        // NEVER a raw token here.
        'token' => env('RAILWAY_API_TOKEN'),

        // This project's UUID (eiaaw-smt). Scopes the usage query to THIS
        // product only — other EIAAW projects have their own P&L.
        'project_id' => env('RAILWAY_PROJECT_ID', 'a8e6c372-b44e-470a-b470-2d6ab36bf9ff'),

        'endpoint' => env('RAILWAY_API_ENDPOINT', 'https://backboard.railway.com/graphql/v2'),

        // Cache the API result this many seconds (the dashboard figure barely
        // moves intra-hour; no need to hit the API on every 30s page poll).
        'cache_ttl' => (int) env('RAILWAY_COST_CACHE_TTL', 3600),

        // Published Railway unit prices (USD). Used to convert the usage-query
        // resource quantities into dollars. Source: docs.railway.com pricing.
        'unit_prices_usd' => [
            'cpu_vcpu_minute' => (float) env('RAILWAY_PRICE_CPU_MIN', 0.000463),
            'memory_gb_minute' => (float) env('RAILWAY_PRICE_MEM_MIN', 0.000231),
            'disk_gb_month' => (float) env('RAILWAY_PRICE_DISK_MONTH', 0.15),
            'network_tx_gb' => (float) env('RAILWAY_PRICE_EGRESS_GB', 0.05),
            'backup_gb_month' => (float) env('RAILWAY_PRICE_BACKUP_MONTH', 0.15),
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
