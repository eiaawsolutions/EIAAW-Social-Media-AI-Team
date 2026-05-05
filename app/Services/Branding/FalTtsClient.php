<?php

namespace App\Services\Branding;

use App\Models\AiCost;
use App\Models\Brand;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client around FAL.AI's text-to-speech endpoints.
 *
 * Default model: fal-ai/kokoro/american-english (Kokoro-82M, open-weights)
 *   - Cost: ~\$0.001 per 1k chars (≈ \$0.01 per 5–8s clip)
 *   - Quality: "good not great" — robotic on long sentences, fine on short
 *     editorial 5–8s clips
 *   - Returns: { audio: { url }, chunks: [{ text, timestamp: [start, end] }] }
 *     where each chunk is a token + timestamp in seconds. We aggregate
 *     chunks into 1-3 word subtitle cues.
 *
 * Fallback / quality upgrade: flip services.fal.tts_model to:
 *   - fal-ai/playht/tts/v3 (~\$0.06/clip, much higher quality)
 *   - fal-ai/elevenlabs/tts/eleven-v3 (~\$0.10/clip, top-tier)
 *
 * Auth + billing: same FAL_API_KEY as image/video. Cost rolls up under
 * the existing `fal` agent_role in ai_costs (we use 'branding.tts' for
 * filterability).
 */
class FalTtsClient
{
    private const PRICING_USD_PER_1K_CHARS = [
        'fal-ai/kokoro/american-english' => 0.001,
        'fal-ai/kokoro/british-english' => 0.001,
        'fal-ai/playht/tts/v3' => 0.006,
        'fal-ai/elevenlabs/tts/eleven-v3' => 0.012,
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $voice,
        private readonly int $timeout = 60,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('FAL.AI api key not configured.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('services.fal.api_key'),
            model: (string) config('services.fal.tts_model', 'fal-ai/kokoro/american-english'),
            voice: (string) config('services.fal.tts_voice', 'af_heart'),
            timeout: (int) config('services.fal.tts_request_timeout', 60),
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
     * Synthesise a voiceover from text. Returns the audio URL (FAL CDN)
     * + chunked timestamps for subtitle generation.
     *
     * @return array{
     *   audio_url:string,
     *   model:string,
     *   voice:string,
     *   duration_seconds:float,
     *   chunks:array<int,array{text:string,start:float,end:float}>,
     *   cost_usd:float,
     *   latency_ms:int,
     * }
     *
     * @throws RuntimeException on API or shape failures.
     */
    public function synthesize(string $text, ?Brand $brand = null): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('FalTtsClient: text cannot be empty.');
        }

        // Kokoro accepts "prompt" as the text input. PlayHT/ElevenLabs use
        // "input". Be defensive: pass both keys; the model ignores the
        // unrecognised one.
        $payload = [
            'prompt' => $text,
            'input' => $text,
            'voice' => $this->voice,
        ];

        $startedAt = (int) (microtime(true) * 1000);

        $response = $this->client()->post('/' . ltrim($this->model, '/'), $payload);
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'FAL.AI TTS %s failed: HTTP %d — %s',
                $this->model,
                $response->status(),
                substr($response->body(), 0, 400),
            ));
        }

        $body = $response->json() ?? [];
        // Possible response shapes:
        //   Kokoro: { audio: { url, content_type, duration }, chunks: [...] }
        //   PlayHT: { audio_url, ... }
        //   ElevenLabs: { audio: { url, ... } }
        $audioUrl = $body['audio']['url']
            ?? $body['audio_url']
            ?? $body['url']
            ?? null;
        if (! is_string($audioUrl) || $audioUrl === '') {
            throw new RuntimeException('FAL TTS response missing audio URL. Body: ' . substr((string) $response->body(), 0, 400));
        }

        $duration = (float) (
            $body['audio']['duration']
            ?? $body['duration']
            ?? $this->estimateDurationSeconds($text)
        );

        $chunks = $this->normalizeChunks($body, $text, $duration);

        $costUsd = $this->calculateCost($text);

        if ($brand) {
            try {
                AiCost::create([
                    'workspace_id' => $brand->workspace_id,
                    'brand_id' => $brand->id,
                    'agent_role' => 'branding.tts',
                    'provider' => 'fal',
                    'model_id' => $this->model,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cost_usd' => $costUsd,
                    'cost_myr' => round($costUsd * 4.7, 4),
                    'called_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('FalTtsClient: cost ledger insert failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'audio_url' => $audioUrl,
            'model' => $this->model,
            'voice' => $this->voice,
            'duration_seconds' => $duration,
            'chunks' => $chunks,
            'cost_usd' => $costUsd,
            'latency_ms' => (int) ((microtime(true) * 1000) - $startedAt),
        ];
    }

    /**
     * Normalise the chunk list from whichever shape the model returned. If
     * the model didn't return word-level timestamps (some PlayHT responses),
     * we even-distribute the words across the audio duration so subtitles
     * still line up roughly with the voice.
     *
     * @return array<int,array{text:string,start:float,end:float}>
     */
    private function normalizeChunks(array $body, string $text, float $duration): array
    {
        $rawChunks = $body['chunks'] ?? $body['words'] ?? $body['timestamps'] ?? [];

        if (is_array($rawChunks) && ! empty($rawChunks)) {
            $chunks = [];
            foreach ($rawChunks as $chunk) {
                $chunkText = (string) ($chunk['text'] ?? $chunk['word'] ?? '');
                if ($chunkText === '') continue;
                $start = (float) ($chunk['timestamp'][0] ?? $chunk['start'] ?? 0.0);
                $end = (float) ($chunk['timestamp'][1] ?? $chunk['end'] ?? $start + 0.3);
                $chunks[] = ['text' => $chunkText, 'start' => $start, 'end' => $end];
            }
            if (! empty($chunks)) return $chunks;
        }

        // Fallback: even-distribute words across the audio duration. Less
        // accurate than real timestamps but always produces working subtitles.
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));
        if (empty($words)) return [];

        $perWord = $duration > 0 ? $duration / count($words) : 0.3;
        $chunks = [];
        $cursor = 0.0;
        foreach ($words as $word) {
            $chunks[] = [
                'text' => $word,
                'start' => round($cursor, 3),
                'end' => round($cursor + $perWord, 3),
            ];
            $cursor += $perWord;
        }
        return $chunks;
    }

    /**
     * Estimate duration when the model didn't report it. Average English
     * narration is ~150 words/minute = ~2.5 words/second.
     */
    private function estimateDurationSeconds(string $text): float
    {
        $wordCount = count(preg_split('/\s+/', trim($text)) ?: []);
        return max(2.0, round($wordCount / 2.5, 2));
    }

    private function calculateCost(string $text): float
    {
        $rate = self::PRICING_USD_PER_1K_CHARS[$this->model] ?? 0.001;
        return round((mb_strlen($text) / 1000) * $rate, 6);
    }
}
