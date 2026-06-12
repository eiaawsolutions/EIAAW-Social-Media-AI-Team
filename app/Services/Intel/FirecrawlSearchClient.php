<?php

namespace App\Services\Intel;

use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Live web search via Firecrawl's /search endpoint. Used by MarketIntelAgent
 * to discover industry-market and trend signals for a brand. Every result
 * carries a source URL — which is exactly what the MarketSignalNormalizer
 * verification gate requires (no URL → discarded).
 *
 * Reuses the already-wired Firecrawl credentials/config (services.firecrawl);
 * no new vendor. Same best-effort contract as LinkedinAdLibraryFirecrawlClient:
 * any failure (no key, timeout, malformed response) returns [] rather than
 * blocking the agent — empty market intel is a soft signal, not a hard error.
 */
class FirecrawlSearchClient
{
    public function __construct(
        private readonly Http $http = new Http(['timeout' => 60, 'allow_redirects' => true]),
    ) {}

    /**
     * Run a single search query. Returns ranked results with source URLs.
     *
     * @return array<int,array{title:string,url:string,snippet:string,published_at:?string}>
     */
    public function search(string $query, int $limit = 8): array
    {
        $apiKey = config('services.firecrawl.api_key');
        if (! $apiKey) {
            Log::info('Firecrawl: no API key configured, skipping market search', ['query' => $query]);

            return [];
        }

        $base = rtrim((string) config('services.firecrawl.base_url'), '/');
        $timeout = (int) config('services.firecrawl.request_timeout', 60);

        try {
            $res = $this->http->post($base.'/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'query' => $query,
                    'limit' => max(1, $limit),
                ],
                'timeout' => $timeout,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Firecrawl: market search failed', [
                'query' => $query,
                'error' => substr($e->getMessage(), 0, 240),
            ]);

            return [];
        }

        $payload = json_decode((string) $res->getBody(), true);
        $rows = $payload['data'] ?? $payload['results'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (array_slice($rows, 0, max(1, $limit)) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'url' => trim((string) ($row['url'] ?? '')),
                'snippet' => trim((string) ($row['description'] ?? $row['snippet'] ?? $row['content'] ?? '')),
                'published_at' => isset($row['publishedDate']) || isset($row['published_at'])
                    ? trim((string) ($row['publishedDate'] ?? $row['published_at']))
                    : null,
            ];
        }

        return $out;
    }
}
