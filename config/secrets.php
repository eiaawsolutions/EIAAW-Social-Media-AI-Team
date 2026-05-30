<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Infisical backend (resolved by App\Providers\SecretsServiceProvider)
    |--------------------------------------------------------------------------
    | These credentials bootstrap the Infisical client. They are the ONLY
    | raw secrets that must live in .env; every other secret can be replaced
    | with a `secret://project/env/name` handle and resolved at boot.
    */
    'infisical' => [
        'enabled' => env('INFISICAL_RESOLVER_ENABLED', false),
        'site_url' => env('INFISICAL_SITE_URL', 'https://app.infisical.com'),
        'client_id' => env('INFISICAL_APP_CLIENT_ID'),
        'client_secret' => env('INFISICAL_APP_CLIENT_SECRET'),
        'project_id' => env('INFISICAL_PROJECT_ID'),
        'environment' => env('INFISICAL_ENVIRONMENT', 'prod'),
        'cache_ttl' => (int) env('INFISICAL_CACHE_TTL', 300),
        'request_timeout' => (int) env('INFISICAL_REQUEST_TIMEOUT', 5),

        /*
        |----------------------------------------------------------------------
        | Cross-project map (slug → Infisical workspaceId)
        |----------------------------------------------------------------------
        | A `secret://<project>/<env>/<path>/<NAME>` handle whose <project>
        | segment matches a key here resolves against THAT project's workspace.
        | Any handle whose segment is empty, a UUID, this project's own slug, or
        | an unknown slug resolves against `project_id` above (the default) — so
        | every pre-existing handle keeps working unchanged.
        |
        | The machine identity must be granted READ access (in the Infisical UI)
        | to every project listed here. Listing a project the identity cannot
        | read just makes its handles fail to resolve (fail-open: the app boots,
        | the dependent feature falls back / logs an error).
        |
        | eiaaw-all-projects holds secrets shared across all EIAAW products
        | (e.g. the Railway billing token used by the HQ cost monitor).
        */
        'projects' => [
            'eiaaw-all-projects' => env('INFISICAL_PROJECT_ID_ALL', '2bca9bc9-330d-4664-b371-6b8ee2758438'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Config paths to resolve
    |--------------------------------------------------------------------------
    | Explicit allow-list of dot-delimited config keys that may hold
    | `secret://` handles. The provider only touches these.
    */
    'resolve' => [
        // AI providers
        'services.anthropic.api_key',
        'services.fal.api_key',
        // Daily caps are configured via Infisical so an operator can
        // raise them without a deploy. Numeric handles (cast in services.fal).
        'services.fal.daily_cap_usd',
        'services.fal.video_daily_cap_usd',
        'services.voyage.api_key',

        // Publishing
        // services.blotato.api_key is the HQ-only fallback (resolved at boot
        // from the .env handle). Per-workspace keys live on
        // workspaces.blotato_api_key_handle and are resolved on demand by
        // BlotatoClient::forWorkspace() — they're NOT in this allow-list
        // because they're per-row and there's no fixed config path to rewrite.
        'services.blotato.api_key',
        // Metricool (Blotato-replacement evaluation). ONE shared token covers
        // all brands (blogId scopes each call) — unlike Blotato there is no
        // per-workspace handle, so a single fixed config path is correct here.
        // The numeric user_id is NOT a secret and is read from plain env.
        'services.metricool.api_token',

        // Mail
        'services.mailgun.secret',
        'services.resend.key',
        'mail.mailers.smtp.password',

        // Storage (Cloudflare R2 via S3-compatible disk)
        'filesystems.disks.r2.key',
        'filesystems.disks.r2.secret',

        // Database
        'database.connections.pgsql.password',
        'database.redis.default.password',

        // Billing
        'services.stripe.key',
        'services.stripe.secret',
        'services.stripe.webhook_secret',
        // Cashier reads its own config block — keep these in sync with services.stripe.*
        // so $cashier->stripe() and any direct Cashier helpers see resolved values.
        'cashier.key',
        'cashier.secret',
        'cashier.webhook.secret',
        'services.billplz.api_key',
        'services.billplz.x_signature',

        // Auth helpers
        'app.key',

        // Railway billing API token (Account/Workspace token) — read-only
        // usage data for the HQ Cost Monitor's live Railway line. Workspace-
        // scoped, so it must be an Account or Workspace token, never a project
        // token. Resolved from a secret:// handle in RAILWAY_API_TOKEN.
        'costs.railway.token',
    ],
];
