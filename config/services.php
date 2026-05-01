<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    | This file stores credentials for third party services. EIAAW projects
    | resolve `secret://...` handles via App\Providers\SecretsServiceProvider
    | at boot. See config/secrets.php for the resolution allow-list.
    */

    // ─── AI providers ───────────────────────────────────────────────
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Default model used by all agents unless overridden per-agent.
        'default_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-6'),
        'cheap_model' => env('ANTHROPIC_CHEAP_MODEL', 'claude-haiku-4-5-20251001'),
        'request_timeout' => (int) env('ANTHROPIC_REQUEST_TIMEOUT', 120),
        'max_retries' => (int) env('ANTHROPIC_MAX_RETRIES', 2),
    ],

    'fal' => [
        // FAL.AI gateway for image generation (Flux Pro 1.1, Wan video, etc.)
        'api_key' => env('FAL_API_KEY'),
        'image_model' => env('FAL_IMAGE_MODEL', 'fal-ai/flux-pro/v1.1'),
        'request_timeout' => (int) env('FAL_REQUEST_TIMEOUT', 180),
    ],

    // ─── Publishing ─────────────────────────────────────────────────
    'blotato' => [
        'api_key' => env('BLOTATO_API_KEY'),
        'base_url' => env('BLOTATO_BASE_URL', 'https://backend.blotato.com'),
        'request_timeout' => (int) env('BLOTATO_REQUEST_TIMEOUT', 30),
    ],

    // ─── Billing ────────────────────────────────────────────────────
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'billplz' => [
        'api_key' => env('BILLPLZ_API_KEY'),
        'x_signature' => env('BILLPLZ_X_SIGNATURE'),
        'collection_id' => env('BILLPLZ_COLLECTION_ID'),
        'sandbox' => (bool) env('BILLPLZ_SANDBOX', false),
    ],

    // ─── Mail ───────────────────────────────────────────────────────
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
