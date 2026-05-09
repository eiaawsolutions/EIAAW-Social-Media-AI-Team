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
        // FAL.AI gateway for image generation (Flux Schnell default, Wan for video)
        'api_key' => env('FAL_API_KEY'),
        // Flux Schnell: $0.003/image, ~2s, 13x cheaper than Pro. Quality is
        // "good commercial" — fine for daily content, not for hero campaign
        // shots. Lift to fal-ai/flux-pro/v1.1 (premium, $0.04) or
        // fal-ai/recraft-v3 (best at no-text + design) per-call when needed.
        'image_model' => env('FAL_IMAGE_MODEL', 'fal-ai/flux/schnell'),
        // Library-first routing: if the brand has uploaded assets and the
        // BrandAssetPicker finds a semantic match, use that asset (zero
        // cost) instead of calling FAL. Operator can force AI generation
        // per-draft via the 'Generate via FAL' UI action.
        'library_first' => (bool) env('FAL_LIBRARY_FIRST', true),
        // Wan 2.6 i2v is the Q2 2026 quality leader for short-form vertical
        // ($0.50/clip, 5s 720p). Wan 2.6 t2v is the fallback when no still
        // exists yet. Veo 3 sits at higher quality + price; switch later.
        'video_model_image' => env('FAL_VIDEO_MODEL_IMAGE', 'fal-ai/wan-25-preview/image-to-video'),
        'video_model_text' => env('FAL_VIDEO_MODEL_TEXT', 'fal-ai/wan-25-preview/text-to-video'),
        'request_timeout' => (int) env('FAL_REQUEST_TIMEOUT', 180),
        'video_request_timeout' => (int) env('FAL_VIDEO_REQUEST_TIMEOUT', 360),
        // Per-workspace daily caps. Video is 10x image so kept separate
        // and operator can lift either independently via Infisical.
        // 2026-05-05 raise: image $0.50 → $1.50, video $2.40 → $5.00.
        // The old caps tripped within hours of normal multi-brand use, leaving
        // drafts stuck at compliance_failed because Designer kept refusing.
        // The breaker query was also fixed to scope by agent_role+provider so
        // Anthropic/Voice/embedding spend no longer eats the FAL budget.
        'daily_cap_usd' => (float) env('FAL_DAILY_CAP_USD', 1.50),
        'video_daily_cap_usd' => (float) env('FAL_VIDEO_DAILY_CAP_USD', 5.00),
        // FAL TTS endpoint used for voiceovers. Kokoro-82M is the cheapest
        // FAL voice (~$0.001/1k chars, ~$0.01/clip), with PlayHT and
        // ElevenLabs-via-FAL as quality-upgrade flips. Word-level
        // timestamps power burned-in subtitles.
        'tts_model' => env('FAL_TTS_MODEL', 'fal-ai/kokoro/american-english'),
        'tts_voice' => env('FAL_TTS_VOICE', 'af_heart'),
        'tts_request_timeout' => (int) env('FAL_TTS_REQUEST_TIMEOUT', 60),
    ],

    // ─── Branding (image quote stamping + video voiceover/music/subtitles) ──
    // Applied only when EiaawBrandLock::appliesTo($brand) is true. Cost is
    // ~$0.001/image (one Haiku call for quote distillation) + ~$0.01/video
    // (one Kokoro TTS call) — both tracked under the existing FAL/Anthropic
    // ai_costs ledger; no new vendor required.
    'branding' => [
        // Master switch. Set false to bypass branding entirely — useful when
        // FFmpeg is unavailable on a host (local dev without ffmpeg installed)
        // or when isolating the raw FAL output for debugging.
        'enabled' => (bool) env('BRANDING_ENABLED', true),
        // Background music for videos. Set false to publish video with
        // voiceover only (no music bed). Files must live in
        // public/brand/music/*.mp3 — see README.md there.
        'background_music_enabled' => (bool) env('BRANDING_BG_MUSIC_ENABLED', true),
        // FFmpeg binary path. Production (Railway/Nixpacks) installs to
        // /nix/store/.../bin/ffmpeg and resolves via $PATH; local dev on
        // Windows can override with the absolute path to a ffmpeg.exe.
        'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
        'ffprobe_bin' => env('FFPROBE_BIN', 'ffprobe'),
        // Hard cap on FFmpeg subprocess wall time so a stuck encode doesn't
        // exhaust the worker's job timeout.
        'ffmpeg_timeout_seconds' => (int) env('BRANDING_FFMPEG_TIMEOUT', 90),
    ],

    'voyage' => [
        // Voyage-3 embeddings (1024 dim) for brand-voice RAG.
        'api_key' => env('VOYAGE_API_KEY'),
        'model' => env('VOYAGE_MODEL', 'voyage-3'),
    ],

    // ─── Competitor intelligence ────────────────────────────────────
    // Used by CompetitorIntelAgent (weekly Mon 03:00 UTC) to fetch
    // competitor ad creatives. Two providers:
    //   - Meta Ad Library: official API. Token comes from the brand's
    //     existing Blotato Meta connection — no new OAuth flow.
    //   - Firecrawl: scrapes the LinkedIn EU DSA transparency portal
    //     (LinkedIn has no public ad-library API in 2026).
    'meta' => [
        'ad_library_base_url' => env('META_AD_LIBRARY_BASE_URL', 'https://graph.facebook.com/v20.0/ads_archive'),
        'ad_library_request_timeout' => (int) env('META_AD_LIBRARY_REQUEST_TIMEOUT', 30),
    ],

    'firecrawl' => [
        'api_key' => env('FIRECRAWL_API_KEY'),
        'base_url' => env('FIRECRAWL_BASE_URL', 'https://api.firecrawl.dev/v1'),
        'request_timeout' => (int) env('FIRECRAWL_REQUEST_TIMEOUT', 60),
    ],

    'competitor_intel' => [
        'enabled' => (bool) env('COMPETITOR_INTEL_ENABLED', true),
        // Cap per-brand fetch volume so a misconfigured handle list can't
        // burn the workspace's daily LLM/Firecrawl budget on intel alone.
        'max_handles_per_brand' => (int) env('COMPETITOR_INTEL_MAX_HANDLES', 10),
        'max_ads_per_handle' => (int) env('COMPETITOR_INTEL_MAX_ADS_PER_HANDLE', 25),
        // Rolling retention; rows past this are pruned by the agent itself.
        'retention_days' => (int) env('COMPETITOR_INTEL_RETENTION_DAYS', 30),
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
