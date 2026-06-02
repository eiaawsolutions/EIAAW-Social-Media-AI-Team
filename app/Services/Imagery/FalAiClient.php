<?php

namespace App\Services\Imagery;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client around FAL.AI's serverless image-generation endpoints.
 *
 * Default model: fal-ai/nano-banana (Gemini 2.5 Flash Image) — best prompt
 * adherence in its class at ~$0.039/image; it depicts what the scripted scene
 * brief describes far more faithfully than flux-pro, with fewer hallucinated
 * objects/limbs. Configurable via services.fal.image_model (flip to
 * fal-ai/flux-pro/v1.1 for premium photoreal, or fal-ai/flux/schnell for cheap
 * drafts). Size param is model-aware: Gemini-family models take `aspect_ratio`,
 * flux-family take named `image_size` presets — see generateImage().
 *
 * Auth: Authorization: Key {api_key} header. Polling vs sync: we use the
 * /run/{model} endpoint which queues + waits inline. Generations stay under
 * our 180s request_timeout.
 *
 * What's intentionally NOT here yet:
 *   - LoRA / brand-tuned model selection (v1.2 — needs ckm-design pipeline)
 *   - C2PA provenance signing (deferred until image moderation lands)
 */
class FalAiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $imageModel,
        private readonly int $timeout = 180,
        private readonly ?string $videoModelImage = null,
        private readonly ?string $videoModelText = null,
        private readonly int $videoTimeout = 360,
        private readonly bool $videoNativeAudio = true,
        private readonly ?string $videoModelExtend = null,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException(
                'FAL.AI api key not configured. Set services.fal.api_key (Infisical handle: secret://eiaaw-smt-prod/prod/FAL_API_KEY).'
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('services.fal.api_key'),
            imageModel: (string) config('services.fal.image_model', 'fal-ai/nano-banana'),
            timeout: (int) config('services.fal.request_timeout', 180),
            videoModelImage: (string) config('services.fal.video_model_image', 'fal-ai/veo3/fast/image-to-video'),
            videoModelText: (string) config('services.fal.video_model_text', 'fal-ai/veo3/fast'),
            videoTimeout: (int) config('services.fal.video_request_timeout', 360),
            videoNativeAudio: (bool) config('services.fal.video_native_audio', true),
            videoModelExtend: (string) config('services.fal.video_model_extend', 'fal-ai/veo3.1/extend-video'),
        );
    }

    // ─── Account-lockout breaker ────────────────────────────────────────────
    // When FAL's prepaid balance is exhausted the account is LOCKED and every
    // call 403s identically until an operator tops up. Without a breaker, every
    // Designer/Video run across every workspace makes a full ~11s HTTP round-trip
    // to a locked account before failing — wasting worker time and flooding logs.
    // The breaker is a single shared cache flag: once a lockout is observed we
    // skip the doomed HTTP call for a short cool-off, then probe again. It is
    // process- and queue-wide because the cache store (database in prod) is
    // shared across all workers.

    private const LOCKOUT_CACHE_KEY = 'fal:account_locked';

    /** Cool-off before we probe FAL again after observing a lockout. Short
     *  enough that a top-up restores service within a couple of minutes, long
     *  enough that we don't hammer a still-locked account every few seconds. */
    private const LOCKOUT_COOLOFF_SECONDS = 120;

    /**
     * True if a FAL account lockout was observed within the cool-off window.
     * Callers should treat this exactly like a fresh lockout (degrade to the
     * library, surface the top-up action) without making the HTTP call.
     */
    public static function lockoutActive(): bool
    {
        return (bool) Cache::get(self::LOCKOUT_CACHE_KEY, false);
    }

    /** Open the breaker for the cool-off window. Idempotent. */
    public static function tripLockout(): void
    {
        Cache::put(self::LOCKOUT_CACHE_KEY, true, self::LOCKOUT_COOLOFF_SECONDS);
    }

    /** Close the breaker — called after any successful FAL response, so the
     *  first good call post-top-up immediately restores normal routing. */
    public static function clearLockout(): void
    {
        Cache::forget(self::LOCKOUT_CACHE_KEY);
    }

    /**
     * True when a FAL response is an ACCOUNT lockout (balance exhausted), not a
     * per-request failure. FAL returns HTTP 403 with a body like:
     *   {"detail":"User is locked. Reason: Exhausted balance. Top up your
     *    balance at fal.ai/dashboard/billing."}
     * We match on the status + the distinctive phrases rather than 403 alone,
     * because a genuine permission/key 403 is a different remedy (rotate key)
     * and must NOT trip the balance breaker.
     */
    public static function isAccountLockoutBody(int $status, string $body): bool
    {
        if ($status !== 403) {
            return false;
        }
        $b = strtolower($body);

        return str_contains($b, 'exhausted balance')
            || str_contains($b, 'user is locked')
            || str_contains($b, 'top up your balance');
    }

    /**
     * Inspect a failed response: if it's an account lockout, trip the breaker
     * and throw the typed FalAccountLockedException; otherwise throw the generic
     * RuntimeException with the model + status + truncated body. Single place so
     * image, video and extend paths classify failures identically.
     */
    private function throwForFailure(string $model, int $status, string $body): never
    {
        if (self::isAccountLockoutBody($status, $body)) {
            self::tripLockout();
            Log::error('FAL.AI account locked (balance exhausted) — breaker tripped', [
                'model' => $model,
                'cooloff_seconds' => self::LOCKOUT_COOLOFF_SECONDS,
            ]);

            throw new FalAccountLockedException(
                'FAL.AI account locked: prepaid balance exhausted. '
                .'Top up at fal.ai/dashboard/billing to restore image/video generation. '
                .'(provider response: HTTP '.$status.')'
            );
        }

        // Content-policy / safety rejection of THIS request (typically the Veo
        // image-to-video safety checker disliking a photoreal keyframe). Typed
        // so VideoAgent can retry once as text-to-video (drop the keyframe)
        // instead of hard-failing the draft.
        if (self::isContentPolicyBody($status, $body)) {
            throw new FalContentPolicyException(sprintf(
                'FAL.AI %s rejected the content (HTTP %d content-policy): %s',
                $model,
                $status,
                substr($body, 0, 300),
            ));
        }

        throw new RuntimeException(sprintf(
            'FAL.AI %s failed: HTTP %d — %s',
            $model,
            $status,
            substr($body, 0, 400),
        ));
    }

    /**
     * True if a FAL failure is a content-policy / safety refusal of this
     * specific request (NOT an account problem). Veo returns these as HTTP 422
     * with a content_policy_violation type or a "content checker" / "unsafe
     * content" / "could not generate images with the given prompts and images"
     * message. Matched on substrings so a phrasing tweak upstream doesn't slip
     * past us.
     */
    public static function isContentPolicyBody(int $status, string $body): bool
    {
        if ($status !== 422 && $status !== 400) {
            return false;
        }
        $b = strtolower($body);
        foreach ([
            'content_policy_violation',
            'content checker',
            'flagged by a content',
            'unsafe content',
            'could not generate images with the given prompts',
            'safety',
        ] as $needle) {
            if (str_contains($b, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'authorization' => 'Key '.$this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])
            ->baseUrl('https://fal.run')
            ->timeout($this->timeout);
    }

    /**
     * Generate one image. Returns the URL of the generated PNG/JPEG hosted
     * on FAL's CDN (typically https://fal.media/...). The caller is
     * responsible for re-hosting it via Blotato /v2/media before passing
     * to /v2/posts (Blotato won't accept fal.media URLs directly).
     *
     * @param array{
     *   image_size?: string|array{width:int,height:int},
     *   aspect_ratio?: string,
     *   num_inference_steps?: int,
     *   guidance_scale?: float,
     *   num_images?: int,
     *   seed?: int,
     *   safety_tolerance?: string,
     *   negative_prompt?: string,
     * } $options
     *
     * Note: neither the default model (fal-ai/nano-banana) nor flux-pro/v1.1
     * has a negative_prompt field — both silently ignore it (no error on
     * unknown keys), which is why the realism block folds the negatives into
     * the positive prompt. Negative-capable models a workspace may configure
     * (flux/dev, recraft-v3, SD-family) honour it. See
     * ImageCreativeDirection::negativePrompt().
     * @return array{url:string, model:string, latency_ms:int, prompt:string, content_type:?string}
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        // Breaker: if the account was just observed locked, don't make the
        // doomed HTTP call — fail fast with the typed exception so the caller
        // degrades immediately instead of waiting on a 403.
        if (self::lockoutActive()) {
            throw new FalAccountLockedException(
                'FAL.AI account locked: prepaid balance exhausted (cached). '
                .'Top up at fal.ai/dashboard/billing to restore image generation.'
            );
        }

        $payload = array_merge([
            'prompt' => $prompt,
            'image_size' => 'square_hd',
            'num_images' => 1,
            'safety_tolerance' => '2',
        ], $options);

        // Model-aware size param. flux uses named `image_size` presets
        // (square_hd, portrait_16_9); Nano Banana / Gemini / Imagen use
        // `aspect_ratio` (1:1, 9:16, 16:9). Callers pass `image_size` for
        // backwards-compat; when the active model is aspect-ratio-style we
        // translate the preset and drop image_size so the model doesn't
        // silently fall back to its 1:1 default.
        if (self::modelUsesAspectRatio($this->imageModel)) {
            if (! isset($payload['aspect_ratio'])) {
                $payload['aspect_ratio'] = self::imageSizeToAspectRatio((string) ($payload['image_size'] ?? 'square_hd'));
            }
            unset($payload['image_size'], $payload['safety_tolerance']);
            // Nano Banana takes safety_tolerance as an int 1-6; our flux '2'
            // string is harmless to omit (model default 4 is fine for brand
            // imagery). num_images is accepted as-is.
        }

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->client()->post('/'.ltrim($this->imageModel, '/'), $payload);

        if (! $response->successful()) {
            $this->throwForFailure($this->imageModel, $response->status(), $response->body());
        }

        // A good response means the account is usable — close the breaker so the
        // first successful call after a top-up restores normal routing at once.
        self::clearLockout();

        $body = $response->json();
        $url = $body['images'][0]['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('FAL.AI response missing images[0].url. Body: '.substr($response->body(), 0, 400));
        }

        return [
            'url' => $url,
            'model' => $this->imageModel,
            'latency_ms' => (int) ((microtime(true) * 1000) - $startedAt),
            'prompt' => $prompt,
            'content_type' => $body['images'][0]['content_type'] ?? 'image/jpeg',
        ];
    }

    /**
     * Per-platform image size hint. FAL's flux-pro accepts named presets
     * (square_hd, portrait_4_3, portrait_16_9, landscape_4_3, landscape_16_9).
     * Square works on every grid; 9:16 is required for TikTok / Reels /
     * Shorts thumbnails so they don't auto-crop.
     */
    public static function imageSizeForPlatform(string $platform): string
    {
        return match ($platform) {
            'tiktok', 'threads', 'pinterest' => 'portrait_16_9',
            'youtube' => 'landscape_16_9',
            default => 'square_hd',
        };
    }

    /**
     * True if the model expects `aspect_ratio` (Gemini-family: Nano Banana,
     * Imagen) rather than flux's named `image_size` presets. Substring match
     * so versioned ids (nano-banana, nano-banana/edit, imagen4/preview) all
     * route correctly.
     */
    public static function modelUsesAspectRatio(string $model): bool
    {
        $m = strtolower($model);
        foreach (['nano-banana', 'gemini', 'imagen'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate a flux `image_size` preset to the nearest `aspect_ratio`
     * value Nano Banana / Gemini accept (1:1, 9:16, 16:9, 4:5, …). Keeps the
     * per-platform sizing intent intact when swapping model families so a
     * TikTok poster stays vertical instead of defaulting to square.
     */
    public static function imageSizeToAspectRatio(string $imageSize): string
    {
        return match ($imageSize) {
            'portrait_16_9', 'portrait_9_16' => '9:16',
            'portrait_4_3' => '3:4',
            'landscape_16_9' => '16:9',
            'landscape_4_3' => '4:3',
            default => '1:1', // square_hd and anything unknown
        };
    }

    private function videoClient(): PendingRequest
    {
        return Http::withHeaders([
            'authorization' => 'Key '.$this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])
            ->baseUrl('https://fal.run')
            ->timeout($this->videoTimeout);
    }

    /**
     * Generate a short-form video. If image_url is provided, runs the
     * image-to-video model (better brand consistency: the still becomes
     * the keyframe). Otherwise text-to-video.
     *
     * The payload is normalised PER MODEL FAMILY before the request:
     *   - Veo 3 family (default): `duration` is a STRING enum "4s"/"6s"/"8s"
     *     (we snap any integer/other value to the nearest allowed step),
     *     aspect is clamped to 16:9 / 9:16 (i2v also allows 'auto') because
     *     Veo rejects 1:1, and `generate_audio` is injected from config so the
     *     model speaks/scores the clip itself. Veo has no `negative_prompt` on
     *     the Fast endpoint — it is dropped here rather than 422-ing.
     *   - Wan / other families: `duration` stays an integer, 1:1 is allowed,
     *     and negative_prompt is forwarded (Wan honours it).
     *
     * @param array{
     *   image_url?: string,
     *   aspect_ratio?: string,        // '9:16' default for vertical
     *   resolution?: string,          // '720p' default
     *   duration?: int|string,        // seconds (int) — mapped to Veo's "Ns" string
     *   generate_audio?: bool,        // overrides config default for Veo
     *   negative_prompt?: string,
     *   seed?: int,
     * } $options
     * @return array{url:string, model:string, latency_ms:int, prompt:string, content_type:?string, has_native_audio:bool}
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $hasImage = ! empty($options['image_url']);
        $model = $hasImage ? $this->videoModelImage : $this->videoModelText;

        if (empty($model)) {
            throw new RuntimeException('FAL video model not configured.');
        }

        if (self::lockoutActive()) {
            throw new FalAccountLockedException(
                'FAL.AI account locked: prepaid balance exhausted (cached). '
                .'Top up at fal.ai/dashboard/billing to restore video generation.'
            );
        }

        $isVeo = self::isVeoModel($model);

        $payload = array_merge([
            'prompt' => $prompt,
            'aspect_ratio' => '9:16',
            'resolution' => '720p',
            'duration' => 5,
        ], $options);

        $nativeAudio = $this->videoNativeAudio;

        if ($isVeo) {
            // Duration → Veo enum string. Accept either an int (5) or a string
            // ("6s") from the caller and snap to the nearest allowed step.
            $payload['duration'] = self::veoDurationString($payload['duration']);

            // Aspect: Veo Fast supports 16:9 and 9:16 (i2v also 'auto'). Anything
            // else (1:1, unknown) → 9:16 so a square draft still ships vertical.
            $payload['aspect_ratio'] = self::clampVeoAspect((string) $payload['aspect_ratio']);

            // Native audio toggle: per-call override wins, else the configured
            // default. When on, Veo generates dialogue/SFX/music from the prompt
            // and the caller skips the FFmpeg voiceover/music composer.
            $nativeAudio = array_key_exists('generate_audio', $options)
                ? (bool) $options['generate_audio']
                : $this->videoNativeAudio;
            $payload['generate_audio'] = $nativeAudio;

            // Veo Fast has no negative_prompt field — drop it to avoid a 422 /
            // silent ignore. The realism "AVOID …" clauses live in the positive
            // prompt (ImageCreativeDirection::videoRealismBlock) so steering is
            // preserved without the field.
            unset($payload['negative_prompt']);
        }

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->videoClient()->post('/'.ltrim($model, '/'), $payload);

        if (! $response->successful()) {
            $this->throwForFailure($model, $response->status(), $response->body());
        }

        self::clearLockout();

        $body = $response->json();
        // Veo / Wan response shape: { video: { url, content_type } } usually.
        $url = $body['video']['url']
            ?? $body['videos'][0]['url']
            ?? $body['output']['video']['url']
            ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('FAL.AI video response missing video.url. Body: '.substr($response->body(), 0, 400));
        }

        return [
            'url' => $url,
            'model' => $model,
            'latency_ms' => (int) ((microtime(true) * 1000) - $startedAt),
            'prompt' => $prompt,
            'content_type' => $body['video']['content_type']
                ?? $body['videos'][0]['content_type']
                ?? 'video/mp4',
            // True when the returned clip already carries model-generated audio
            // (Veo native audio). The caller uses this to decide whether to skip
            // the voiceover/music composer.
            'has_native_audio' => $isVeo && $nativeAudio,
        ];
    }

    /** Each Veo 3.1 extend-video call appends exactly this many seconds. The
     *  endpoint's `duration` is a fixed "7s" constant — not configurable. */
    public const VEO_EXTEND_STEP_SECONDS = 7;

    /**
     * Extend an existing video by one fixed 7-second Veo 3.1 step, continuing
     * the scene from `prompt`. Used to build clips longer than Veo Fast's 8s
     * single-call cap (8s base + N×7s extends). The source `video_url` must be
     * 720p/1080p and reachable by FAL (the base clip's fal.media URL works).
     *
     * Audio stays continuous: when generate_audio is on, Veo continues the
     * narration/ambience across the seam rather than restarting it.
     *
     * @param array{
     *   aspect_ratio?: string,        // 'auto'|'16:9'|'9:16'
     *   generate_audio?: bool,
     *   negative_prompt?: string,     // Veo 3.1 extend DOES accept this
     *   seed?: int,
     * } $options
     * @return array{url:string, model:string, latency_ms:int, prompt:string, content_type:?string, has_native_audio:bool, added_seconds:int}
     */
    public function extendVideo(string $videoUrl, string $prompt, array $options = []): array
    {
        $model = $this->videoModelExtend;
        if (empty($model)) {
            throw new RuntimeException('FAL extend-video model not configured (services.fal.video_model_extend).');
        }

        if (self::lockoutActive()) {
            throw new FalAccountLockedException(
                'FAL.AI account locked: prepaid balance exhausted (cached). '
                .'Top up at fal.ai/dashboard/billing to restore video generation.'
            );
        }

        $nativeAudio = array_key_exists('generate_audio', $options)
            ? (bool) $options['generate_audio']
            : $this->videoNativeAudio;

        // 'auto' lets Veo keep the source clip's orientation across the seam.
        $aspect = (string) ($options['aspect_ratio'] ?? 'auto');
        if (! in_array($aspect, ['auto', '16:9', '9:16'], true)) {
            $aspect = self::clampVeoAspect($aspect);
        }

        $payload = [
            'prompt' => $prompt,
            'video_url' => $videoUrl,
            'aspect_ratio' => $aspect,
            'resolution' => '720p',
            'generate_audio' => $nativeAudio,
        ];
        if (! empty($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }
        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
        }

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->videoClient()->post('/'.ltrim($model, '/'), $payload);

        if (! $response->successful()) {
            $this->throwForFailure($model, $response->status(), $response->body());
        }

        self::clearLockout();

        $body = $response->json();
        $url = $body['video']['url']
            ?? $body['videos'][0]['url']
            ?? $body['output']['video']['url']
            ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('FAL.AI extend-video response missing video.url. Body: '.substr($response->body(), 0, 400));
        }

        return [
            'url' => $url,
            'model' => $model,
            'latency_ms' => (int) ((microtime(true) * 1000) - $startedAt),
            'prompt' => $prompt,
            'content_type' => $body['video']['content_type'] ?? 'video/mp4',
            'has_native_audio' => $nativeAudio,
            'added_seconds' => self::VEO_EXTEND_STEP_SECONDS,
        ];
    }

    /**
     * How many +7s extend steps are needed to reach a target duration from an
     * 8s (or other) Veo base clip. ceil((target - base) / 7), floored at 0.
     * e.g. target 15s from 8s base → 1 step (15s); 22s → 2 steps (22s).
     */
    public static function extendStepsForTarget(int $targetSeconds, int $baseSeconds): int
    {
        if ($targetSeconds <= $baseSeconds) {
            return 0;
        }

        return (int) ceil(($targetSeconds - $baseSeconds) / self::VEO_EXTEND_STEP_SECONDS);
    }

    /**
     * True if the model is a Google Veo family endpoint (veo3, veo3/fast,
     * veo3/fast/image-to-video, veo3.1/extend-video, future veoN). Substring
     * match so versioned ids route correctly. Drives the Veo-specific payload
     * normalisation in generateVideo() (string duration, generate_audio,
     * aspect clamp).
     */
    public static function isVeoModel(?string $model): bool
    {
        return $model !== null && str_contains(strtolower($model), 'veo');
    }

    /**
     * Veo Fast accepts ONLY these duration strings. We snap any input
     * (int seconds, "5s", 7) to the nearest allowed step so a caller that asks
     * for 5s gets the closest valid clip ("4s") instead of a 422.
     */
    private const VEO_DURATIONS = [4, 6, 8];

    /**
     * Normalise a caller duration (int seconds or "Ns" string) to Veo's
     * "4s"|"6s"|"8s" enum, snapping to the nearest allowed step. Ties round up
     * (5 → 6s) so we never under-deliver the voiceover.
     *
     * @param  int|string  $duration
     */
    public static function veoDurationString(int|string $duration): string
    {
        $seconds = is_string($duration)
            ? (int) preg_replace('/[^0-9]/', '', $duration)
            : (int) $duration;
        if ($seconds <= 0) {
            $seconds = 6;
        }

        $best = self::VEO_DURATIONS[0];
        $bestDelta = PHP_INT_MAX;
        foreach (self::VEO_DURATIONS as $allowed) {
            $delta = abs($allowed - $seconds);
            // < (not <=) means lower steps win exact-distance ties; we flip to
            // round-up by preferring the higher step on an equal delta.
            if ($delta < $bestDelta || ($delta === $bestDelta && $allowed > $best)) {
                $best = $allowed;
                $bestDelta = $delta;
            }
        }

        return $best.'s';
    }

    /**
     * Clamp an aspect to what Veo Fast supports. Veo rejects 1:1 and exotic
     * ratios; everything that isn't a clean landscape maps to vertical 9:16
     * (the dominant short-form surface). i2v also accepts 'auto' but we resolve
     * to an explicit ratio for deterministic safe-zones downstream.
     */
    public static function clampVeoAspect(string $aspect): string
    {
        return match ($aspect) {
            '16:9' => '16:9',
            default => '9:16',
        };
    }

    /**
     * Returns true if the platform routinely accepts short-form video
     * (Reels, Shorts, TikTok, etc.) at all. Used to gate VideoAgent so
     * we don't spend video credit on text-only platforms.
     */
    public static function platformAcceptsVideo(string $platform): bool
    {
        return in_array($platform, [
            'instagram', 'facebook', 'tiktok', 'threads', 'youtube', 'linkedin',
        ], true);
    }
}
