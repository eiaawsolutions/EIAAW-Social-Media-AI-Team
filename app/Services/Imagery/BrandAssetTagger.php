<?php

namespace App\Services\Imagery;

use App\Models\BrandAsset;
use App\Services\Embeddings\EmbeddingService;
use Anthropic\Anthropic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tags + embeds a BrandAsset on upload. Two-step:
 *   1. Claude vision (Haiku, cheapest tier) generates a 1-line description
 *      + 5-10 tags from looking at the image.
 *   2. Voyage embeds the description+tags concat into the brand_assets
 *      embedding column for cosine match by BrandAssetPicker later.
 *
 * Skipped silently for video assets (no vision support yet) — videos get
 * tagged from filename + operator-provided description on upload.
 */
class BrandAssetTagger
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
    ) {}

    public function tag(BrandAsset $asset): void
    {
        if ($asset->media_type !== 'image') {
            // Video: derive tags from filename + brand context only.
            $this->tagFromFilename($asset);
            return;
        }

        try {
            [$description, $tags] = $this->describeViaClaude($asset);
        } catch (\Throwable $e) {
            Log::warning('BrandAssetTagger: vision call failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            // Fall back to filename-only.
            $this->tagFromFilename($asset);
            return;
        }

        $asset->update([
            'description' => $description,
            'tags' => $tags,
        ]);

        $this->embedTagged($asset);
    }

    /**
     * @return array{0: string, 1: array<int, string>}  [description, tags]
     */
    private function describeViaClaude(BrandAsset $asset): array
    {
        $imageBytes = file_get_contents($asset->public_url);
        if ($imageBytes === false || strlen($imageBytes) < 100) {
            throw new \RuntimeException('Asset URL not fetchable: ' . $asset->public_url);
        }

        $base64 = base64_encode($imageBytes);
        $mediaType = $asset->mime_type ?: 'image/jpeg';

        $client = Anthropic::factory()
            ->withApiKey((string) config('services.anthropic.api_key'))
            ->make();

        $response = $client->messages()->create([
            'model' => (string) config('services.anthropic.cheap_model', 'claude-haiku-4-5-20251001'),
            'max_tokens' => 400,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => "You are a brand asset cataloger. Describe this image in one short sentence (≤20 words) for semantic search, then list 5-10 short tags (single words or 2-word phrases) covering: subject, mood, setting, dominant colours, composition. Format your response EXACTLY as:\n\nDESCRIPTION: <one sentence>\nTAGS: <tag1>, <tag2>, <tag3>, ...\n\nNo other text.",
                    ],
                ],
            ]],
        ]);

        $text = $response->content[0]->text ?? '';
        return $this->parseClaudeResponse($text);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function parseClaudeResponse(string $text): array
    {
        $description = '';
        $tags = [];
        if (preg_match('/DESCRIPTION:\s*(.+?)(?:\n|$)/i', $text, $m)) {
            $description = trim($m[1]);
        }
        if (preg_match('/TAGS:\s*(.+?)(?:\n|$)/i', $text, $m)) {
            $tags = array_values(array_filter(array_map(
                fn ($t) => trim(strtolower($t)),
                explode(',', $m[1]),
            )));
        }
        return [$description, $tags];
    }

    private function tagFromFilename(BrandAsset $asset): void
    {
        $base = pathinfo($asset->original_filename ?? 'asset', PATHINFO_FILENAME);
        $clean = trim(preg_replace('/[^a-z0-9]+/i', ' ', $base) ?? '');
        $description = $clean !== '' ? "Uploaded asset: {$clean}" : 'Uploaded asset';
        $tags = $clean !== ''
            ? array_values(array_filter(array_map('strtolower', explode(' ', $clean))))
            : ['uploaded'];

        $asset->update([
            'description' => $description,
            'tags' => $tags,
        ]);

        $this->embedTagged($asset);
    }

    private function embedTagged(BrandAsset $asset): void
    {
        $tagBlob = trim(($asset->description ?? '') . "\nTags: " . implode(', ', $asset->tags ?? []));
        if ($tagBlob === '') return;

        try {
            $vector = $this->embeddings->embed($tagBlob, $asset->brand, $asset->brand?->workspace);
            $asset->update(['embedding' => $vector]);
        } catch (\Throwable $e) {
            Log::warning('BrandAssetTagger: embedding failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
