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
        // Wan 2.6 i2v is the Q2 2026 quality leader for short-form vertical
        // ($0.50/clip, 5s 720p). Wan 2.6 t2v is the fallback when no still
        // exists yet. Veo 3 sits at higher quality + price; switch later.
        'video_model_image' => env('FAL_VIDEO_MODEL_IMAGE', 'fal-ai/wan-25-preview/image-to-video'),
        'video_model_text' => env('FAL_VIDEO_MODEL_TEXT', 'fal-ai/wan-25-preview/text-to-video'),
        'request_timeout' => (int) env('FAL_REQUEST_TIMEOUT', 180),
        'video_request_timeout' => (int) env('FAL_VIDEO_REQUEST_TIMEOUT', 360),
        // Per-workspace daily caps. Video is 10x image so kept separate
        // and operator can lift either independently via Infisical.
        'daily_cap_usd' => (float) env('FAL_DAILY_CAP_USD', 0.50),
        'video_daily_cap_usd' => (float) env('FAL_VIDEO_DAILY_CAP_USD', 2.00),
    ],

    'voyage' => [
        // Voyage-3 embeddings (1024 dim) for brand-voice RAG.
        'api_key' => env('VOYAGE_API_KEY'),
        'model' => env('VOYAGE_MODEL', 'voyage-3'),
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
