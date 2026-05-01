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
        'services.voyage.api_key',

        // Publishing
        'services.blotato.api_key',

        // Mail
        'services.mailgun.secret',
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
        'services.billplz.api_key',
        'services.billplz.x_signature',

        // Auth helpers
        'app.key',
    ],
];
