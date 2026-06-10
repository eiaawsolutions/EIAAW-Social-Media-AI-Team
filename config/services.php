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
        // FAL.AI gateway for image generation (Nano Banana default, Veo 3 Fast for video)
        'api_key' => env('FAL_API_KEY'),
        // Nano Banana (Gemini 2.5 Flash Image), ~$0.039/image: best prompt
        // adherence in its class — it depicts what the scripted scene brief
        // actually describes, with far fewer hallucinated objects/limbs than
        // flux-pro. This is why it's the default: flux-pro produced sloppy,
        // off-brief imagery that didn't track the post copy.
        // IMPORTANT: Nano Banana takes `aspect_ratio` (1:1/9:16/16:9…), NOT
        // flux's named `image_size` presets — FalAiClient::generateImage maps
        // the size param per-model, so flipping this value is safe.
        // Alternatives per-call: fal-ai/flux-pro/v1.1 (premium photoreal,
        // $0.04) or fal-ai/flux/schnell (cheap drafts, $0.003).
        'image_model' => env('FAL_IMAGE_MODEL', 'fal-ai/nano-banana'),
        // Library-first routing: if the brand has uploaded assets and the
        // BrandAssetPicker finds a semantic match, use that asset (zero
        // cost) instead of calling FAL. Operator can force AI generation
        // per-draft via the 'Generate via FAL' UI action.
        'library_first' => (bool) env('FAL_LIBRARY_FIRST', true),
        // Cosine-distance ceiling for the library matcher. Lower = stricter
        // (only a genuinely on-topic uploaded asset is reused; otherwise we
        // fall through to bespoke AI generation that depicts the post). Was
        // 0.45 (loose) — tightened to 0.32 so generic stock no longer wins a
        // weak match over a scripted-brief generation.
        'library_match_distance' => (float) env('FAL_LIBRARY_MATCH_DISTANCE', 0.32),
        // EIAAW house brand: prefer bespoke FAL generation from the scripted
        // scene brief over reusing a generic stock-library asset, so every
        // internal post gets an on-message visual. Client workspaces keep
        // library-first (they upload their own brand-correct photography).
        'internal_prefers_ai' => (bool) env('FAL_INTERNAL_PREFERS_AI', true),
        // Google Veo 3 Fast is the Q2 2026 quality leader for short-form: far
        // better realism, motion coherence and prompt adherence than Wan, plus
        // NATIVE synced audio (Veo speaks/scores the clip from the prompt). We
        // feed it the caption-derived scene brief + the distilled voiceover as
        // spoken dialogue, so the clip says what the post says.
        //   - i2v: fal-ai/veo3/fast/image-to-video — used when a Designer still
        //     exists (keyframe → brand consistency).
        //   - t2v: fal-ai/veo3/fast — used when no still exists yet.
        // Pricing (FAL, 2026-05): $0.10/sec audio-off, $0.15/sec audio-on. A 6s
        // audio-on clip ≈ $0.90; an 8s ≈ $1.20. Veo accepts ONLY 4s/6s/8s
        // durations and 16:9/9:16 aspect (i2v also 'auto'); NO 1:1. The
        // FalAiClient maps our integer seconds → Veo's "Ns" string and clamps
        // aspect, so flipping these IDs is safe.
        // Roll back to Wan by setting these to fal-ai/wan-25-preview/* .
        'video_model_image' => env('FAL_VIDEO_MODEL_IMAGE', 'fal-ai/veo3/fast/image-to-video'),
        'video_model_text' => env('FAL_VIDEO_MODEL_TEXT', 'fal-ai/veo3/fast'),
        // Veo 3.1 extend-video: appends a fixed +7s continuation per call so we
        // can build clips longer than Veo Fast's 8s cap (8s base + N×7s). Used
        // only on the native-audio path. Pricing: $0.20/sec off, $0.40/sec on.
        'video_model_extend' => env('FAL_VIDEO_MODEL_EXTEND', 'fal-ai/veo3.1/extend-video'),
        // Veo native audio: when true the model generates its own dialogue/SFX/
        // music from the prompt and we SKIP the FFmpeg voiceover+music composer.
        // Set false to mute Veo and keep the legacy Kokoro voiceover+music+subs
        // brand composer (only meaningful while a Wan-style model is active).
        'video_native_audio' => (bool) env('FAL_VIDEO_NATIVE_AUDIO', true),
        // Default TARGET clip length in seconds. Base call snaps to {4,6,8};
        // anything >8 is built via +7s extend steps (15 = 8 base + 1 extend).
        // VideoAgent caps the target at MAX_TARGET_SECONDS (22) regardless.
        'video_duration_seconds' => (int) env('FAL_VIDEO_DURATION_SECONDS', 15),
        'request_timeout' => (int) env('FAL_REQUEST_TIMEOUT', 180),
        'video_request_timeout' => (int) env('FAL_VIDEO_REQUEST_TIMEOUT', 360),
        // NO per-day USD FAL breaker (removed 2026-06-01). Image/video generation
        // is bound ONLY by the monthly volume caps in config/billing.php — the
        // customer self-paces within the month. The old daily_cap_usd /
        // video_daily_cap_usd keys were a hidden daily usage cap and were removed;
        // do not reintroduce. See [[no-daily-fal-cap]].
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
        // Infographic/poster text is drawn PROGRAMMATICALLY (FFmpeg drawtext via
        // InfographicComposer) on a text-free AI background — diffusion models
        // garble dense baked-in text ("Step 3"→"Step 33", "outreach"→"outrech").
        // Set false to roll back to the legacy "ask Nano Banana to render the
        // words" path (ImageCreativeDirection::poster/infographicDirective).
        'compose_infographics' => (bool) env('BRANDING_COMPOSE_INFOGRAPHICS', true),
        // Separate (larger) wall-time cap for the multi-block infographic
        // filtergraph, which draws far more text blocks than the quote stamp.
        'infographic_timeout_seconds' => (int) env('BRANDING_INFOGRAPHIC_TIMEOUT', 120),
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

        // ─── Graph API for first-party post analytics ────────────────────
        // Direct IG/FB insights pull for HQ's OWN accounts (Standard Access —
        // no Meta App Review needed for accounts we own/manage). Auth uses a
        // Business Manager SYSTEM USER token, which is permanent (never
        // expires) — ideal for server-to-server analytics with no recurring
        // user re-login. Per the EIAAW Deploy Contract the raw token lives in
        // Infisical; META_GRAPH_SYSTEM_USER_TOKEN holds a `secret://…` handle
        // that SecretsServiceProvider resolves at boot. Empty = Meta provider
        // disabled (collector falls back to Blotato/CSV), so this is safe to
        // ship before the token is provisioned.
        //
        // Customer accounts (Advanced Access via per-customer OAuth) are a
        // later phase — see MetricsProviderRouter for the seam.
        'graph' => [
            'base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
            'api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
            'system_user_token' => env('META_GRAPH_SYSTEM_USER_TOKEN'), // secret:// handle, resolved at boot
            'request_timeout' => (int) env('META_GRAPH_REQUEST_TIMEOUT', 30),
        ],
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
    // Provider selection for the publish path (SubmitScheduledPost via
    // PublisherFactory). Default 'metricool' — the Blotato→Metricool switch.
    // 'blotato' is the rollback path until Blotato is decommissioned in a
    // follow-up PR. Flip with PUBLISH_PROVIDER, no redeploy of code.
    'publishing' => [
        'provider' => env('PUBLISH_PROVIDER', 'metricool'),
    ],

    'blotato' => [
        'api_key' => env('BLOTATO_API_KEY'),
        'base_url' => env('BLOTATO_BASE_URL', 'https://backend.blotato.com'),
        'request_timeout' => (int) env('BLOTATO_REQUEST_TIMEOUT', 30),
    ],

    // ─── Metricool (evaluation: candidate Blotato replacement) ──────
    // Unlike Blotato, Metricool is NATIVELY multi-brand: ONE account holds N
    // brands (each a blogId), and ONE API token (sent as the X-Mc-Auth header)
    // covers every brand. So — deliberately — there is a SINGLE token handle
    // here, NOT a per-workspace handle like blotato_api_key_handle. Each SMT
    // client maps to a Metricool brand via brands.metricool_blog_id; isolation
    // is enforced server-side by scoping every call to the right blogId.
    // Per the EIAAW Deploy Contract the raw token lives in Infisical;
    // METRICOOL_API_TOKEN holds a `secret://…` handle resolved at boot. The
    // user_id is the numeric Metricool account id (not secret) and pairs with
    // blogId on every request. Empty token = integration dormant (probes no-op
    // with a clear message). This block exists for the verification probes
    // (metricool:probe-metrics / metricool:probe-publish) — wiring the live
    // collector/publisher is gated on those passing. See memory
    // metricool-evaluation + metricool-multitenancy.
    'metricool' => [
        'api_token' => env('METRICOOL_API_TOKEN'),   // secret:// handle → X-Mc-Auth token
        'user_id' => env('METRICOOL_USER_ID'),       // numeric account id (non-secret)
        'base_url' => env('METRICOOL_BASE_URL', 'https://app.metricool.com/api'),
        'request_timeout' => (int) env('METRICOOL_REQUEST_TIMEOUT', 30),

        // Growth-dashboard guardrails (the /agency/performance page). That page
        // does up to ~13 SERIAL Metricool calls inside the web request to build
        // the followers + impressions timelines; with the publish-path default
        // (30s × retry 2 ≈ 90s/call) a single slow network blows past PHP's
        // max_execution_time and the page 500s with an uncatchable fatal. So the
        // synchronous render uses a SHORT per-call timeout and an overall
        // wall-clock BUDGET: once spent, remaining networks degrade to an
        // honest "couldn't reach Metricool" tile and the page still renders.
        // Workers (collector/publisher/probes) keep request_timeout, untouched.
        'growth_call_timeout' => (int) env('METRICOOL_GROWTH_CALL_TIMEOUT', 6),  // seconds per network call
        'growth_time_budget' => (int) env('METRICOOL_GROWTH_TIME_BUDGET', 18),   // seconds total, under PHP's 30s ceiling
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
