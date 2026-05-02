<?php

namespace App\Services\Imagery;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client around FAL.AI's serverless image-generation endpoints.
 *
 * Default model: fal-ai/flux-pro/v1.1 — the best photorealistic + design
 * compositional quality at ~$0.04/image. Configurable via
 * services.fal.image_model so a workspace can flip to flux-schnell for
 * draft volume (~$0.003/image, 4x cheaper, less prompt-faithful).
 *
 * Auth: Authorization: Key {api_key} header. Polling vs sync: we use the
 * /run/{model} endpoint which queues + waits inline. Long generations
 * (Flux Pro typically 8-15s) stay under our 180s request_timeout.
 *
 * What's intentionally NOT here yet:
 *   - LoRA / brand-tuned model selection (v1.2 — needs ckm-design pipeline)
 *   - C2PA provenance signing (deferred until image moderation lands)
 *   - Aspect-ratio routing per platform — currently the agent picks 1:1
 *     for IG/FB/LI, 9:16 for TikTok/Threads/Reels by passing image_size.
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
            imageModel: (string) config('services.fal.image_model', 'fal-ai/flux-pro/v1.1'),
            timeout: (int) config('services.fal.request_timeout', 180),
            videoModelImage: (string) config('services.fal.video_model_image', 'fal-ai/wan-25-preview/image-to-video'),
            videoModelText: (string) config('services.fal.video_model_text', 'fal-ai/wan-25-preview/text-to-video'),
            videoTimeout: (int) config('services.fal.video_request_timeout', 360),
        );
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
                'authorization' => 'Key ' . $this->apiKey,
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
     *   num_inference_steps?: int,
     *   guidance_scale?: float,
     *   num_images?: int,
     *   seed?: int,
     *   safety_tolerance?: string,
     * } $options
     *
     * @return array{url:string, model:string, latency_ms:int, prompt:string, content_type:?string}
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $payload = array_merge([
            'prompt' => $prompt,
            'image_size' => 'square_hd',
            'num_images' => 1,
            'safety_tolerance' => '2',
        ], $options);

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->client()->post('/' . ltrim($this->imageModel, '/'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'FAL.AI %s failed: HTTP %d — %s',
                $this->imageModel,
                $response->status(),
                substr($response->body(), 0, 400),
            ));
        }

        $body = $response->json();
        $url = $body['images'][0]['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('FAL.AI response missing images[0].url. Body: ' . substr($response->body(), 0, 400));
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

    private function videoClient(): PendingRequest
    {
        return Http::withHeaders([
                'authorization' => 'Key ' . $this->apiKey,
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
     * @param array{
     *   image_url?: string,
     *   aspect_ratio?: string,        // '9:16' default for vertical
     *   resolution?: string,          // '720p' default
     *   duration?: int,               // seconds, model-specific cap
     *   negative_prompt?: string,
     *   seed?: int,
     * } $options
     *
     * @return array{url:string, model:string, latency_ms:int, prompt:string, content_type:?string}
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $hasImage = ! empty($options['image_url']);
        $model = $hasImage ? $this->videoModelImage : $this->videoModelText;

        if (empty($model)) {
            throw new RuntimeException('FAL video model not configured.');
        }

        $payload = array_merge([
            'prompt' => $prompt,
            'aspect_ratio' => '9:16',
            'resolution' => '720p',
            'duration' => 5,
        ], $options);

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->videoClient()->post('/' . ltrim($model, '/'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'FAL.AI %s failed: HTTP %d — %s',
                $model,
                $response->status(),
                substr($response->body(), 0, 400),
            ));
        }

        $body = $response->json();
        // Wan / Veo response shape: { video: { url, content_type } } usually.
        $url = $body['video']['url']
            ?? $body['videos'][0]['url']
            ?? $body['output']['video']['url']
            ?? null;
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('FAL.AI video response missing video.url. Body: ' . substr($response->body(), 0, 400));
        }

        return [
            'url' => $url,
            'model' => $model,
            'latency_ms' => (int) ((microtime(true) * 1000) - $startedAt),
            'prompt' => $prompt,
            'content_type' => $body['video']['content_type']
                ?? $body['videos'][0]['content_type']
                ?? 'video/mp4',
        ];
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
