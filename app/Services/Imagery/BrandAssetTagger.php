<?php

namespace App\Services\Imagery;

use App\Models\BrandAsset;
use App\Services\Embeddings\EmbeddingService;
use Anthropic\Client;
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

        // Best-effort during tagging: a missing embedding still leaves a tagged
        // asset. The explicit editor flow calls reembed() directly and DOES
        // surface failures (see BrandAssetEditor).
        $this->reembedSafely($asset);
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

        $client = new Client(
            apiKey: (string) config('services.anthropic.api_key'),
        );

        // anthropic-ai/sdk v0.17: named params (camelCase), `->messages->create`
        // as a property. Same SDK shape as App\Services\Llm\LlmGateway.
        $response = $client->messages->create(
            maxTokens: 400,
            model: (string) config('services.anthropic.cheap_model', 'claude-haiku-4-5-20251001'),
            messages: [[
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
        );

        return $this->parseClaudeResponse($this->extractText($response));
    }

    /** Concatenate the text blocks from a messages.create response. */
    private function extractText(object $response): string
    {
        $out = '';
        foreach ($response->content ?? [] as $block) {
            if (($block->type ?? null) === 'text') {
                $out .= $block->text ?? '';
            }
        }
        return $out;
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

        $this->reembedSafely($asset);
    }

    /**
     * Re-embed an asset from its CURRENT description + tags — embed only, NO
     * Claude vision call. This is what the description editor needs after an
     * operator edits the text: the picker's semantic match must follow the new
     * words, but re-running vision (tag()) would be a needless cost and could
     * overwrite the operator's edit. Re-throws on failure so the editor page
     * can surface it; the tagging flow wraps this in reembedSafely().
     */
    public function reembed(BrandAsset $asset): void
    {
        $tagBlob = trim(($asset->description ?? '') . "\nTags: " . implode(', ', $asset->tags ?? []));
        if ($tagBlob === '') {
            return;
        }

        $vector = $this->embeddings->embed($tagBlob, $asset->brand, $asset->brand?->workspace);
        $asset->update(['embedding' => $vector]);
    }

    /** Best-effort wrapper used by the tagging paths — a failed embed must not
     *  lose a freshly-tagged asset. The editor flow calls reembed() directly. */
    private function reembedSafely(BrandAsset $asset): void
    {
        try {
            $this->reembed($asset);
        } catch (\Throwable $e) {
            Log::warning('BrandAssetTagger: embedding failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
