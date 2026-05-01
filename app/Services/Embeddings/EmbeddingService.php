<?php

namespace App\Services\Embeddings;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Workspace;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;
use RuntimeException;

/**
 * Voyage-3 embeddings — 1024 dimensions, matches our pgvector schema.
 *
 * Why Voyage: it's the embedding line Anthropic acquired and is the recommended
 * pairing with Claude for RAG. Direct REST call (no PHP SDK), keeps costs low
 * (~$0.02 per 1M tokens for input).
 *
 * Usage:
 *   $vector = app(EmbeddingService::class)->embed("hello world");
 *   $manyVectors = app(EmbeddingService::class)->embedMany(["a", "b", "c"]);
 *
 * Pass $brand and $workspace where possible so cost gets logged to the per-tenant
 * ai_costs ledger. Skip them only for system warmup / eval contexts.
 */
class EmbeddingService
{
    public const DIMENSIONS = 1024;
    private const MODEL = 'voyage-3';
    private const MAX_BATCH = 128; // Voyage allows 1000 but we keep the per-call payload reasonable
    private const PRICE_PER_1M_TOKENS = 0.06; // USD — Voyage-3 input

    public function __construct(private readonly Client $http = new Client(['timeout' => 30])) {}

    /**
     * Embed a single document. Returns a pgvector Vector ready to assign to a
     * model attribute (BrandStyle::embedding, BrandCorpusItem::embedding).
     */
    public function embed(
        string $text,
        ?Brand $brand = null,
        ?Workspace $workspace = null,
        string $inputType = 'document',
    ): Vector {
        $vectors = $this->embedMany([$text], $brand, $workspace, $inputType);
        return $vectors[0];
    }

    /**
     * Batch embed. Returns ordered Vector array matching $texts.
     *
     * @param array<int, string> $texts
     * @return array<int, Vector>
     */
    public function embedMany(
        array $texts,
        ?Brand $brand = null,
        ?Workspace $workspace = null,
        string $inputType = 'document',
    ): array {
        if (empty($texts)) return [];

        $apiKey = env('VOYAGE_API_KEY');
        if (empty($apiKey)) {
            throw new RuntimeException(
                'Voyage API key not configured. Set VOYAGE_API_KEY in your env (or via Infisical handle).'
            );
        }

        $out = [];
        foreach (array_chunk($texts, self::MAX_BATCH) as $chunk) {
            $vectors = $this->callVoyage($chunk, $inputType, $apiKey, $brand, $workspace);
            foreach ($vectors as $v) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Embed a query (used at retrieval time — different input_type than documents).
     */
    public function embedQuery(string $query, ?Brand $brand = null, ?Workspace $workspace = null): Vector
    {
        return $this->embed($query, $brand, $workspace, 'query');
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, Vector>
     */
    private function callVoyage(array $texts, string $inputType, string $apiKey, ?Brand $brand, ?Workspace $workspace): array
    {
        try {
            $response = $this->http->post('https://api.voyageai.com/v1/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'input' => $texts,
                    'model' => self::MODEL,
                    'input_type' => $inputType,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('EmbeddingService: Voyage call failed', [
                'error' => $e->getMessage(),
                'count' => count($texts),
            ]);
            throw $e;
        }

        $body = json_decode((string) $response->getBody(), true);
        $data = $body['data'] ?? [];
        if (! is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException('Voyage response shape unexpected — count mismatch.');
        }

        $totalTokens = (int) ($body['usage']['total_tokens'] ?? 0);
        if ($totalTokens > 0 && $brand && $workspace) {
            $costUsd = round(($totalTokens / 1_000_000) * self::PRICE_PER_1M_TOKENS, 6);
            try {
                AiCost::create([
                    'workspace_id' => $workspace->id,
                    'brand_id' => $brand->id,
                    'agent_role' => 'embeddings',
                    'provider' => 'voyage',
                    'model_id' => self::MODEL,
                    'input_tokens' => $totalTokens,
                    'output_tokens' => 0,
                    'cost_usd' => $costUsd,
                    'cost_myr' => round($costUsd * 4.7, 4),
                    'called_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('EmbeddingService: cost ledger insert failed', ['error' => $e->getMessage()]);
            }
        }

        // Voyage returns indexed objects — sort by 'index' to keep order stable.
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $out = [];
        foreach ($data as $row) {
            $embedding = $row['embedding'] ?? null;
            if (! is_array($embedding) || count($embedding) !== self::DIMENSIONS) {
                throw new RuntimeException('Voyage returned unexpected embedding shape.');
            }
            $out[] = new Vector($embedding);
        }
        return $out;
    }
}
