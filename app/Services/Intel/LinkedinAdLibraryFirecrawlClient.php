<?php

namespace App\Services\Intel;

use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads competitor LinkedIn marketing content via Firecrawl SEARCH.
 *
 * Why not the LinkedIn Ad Library portal? Empirically (verified 2026-07 in prod)
 * LinkedIn HARD-BLOCKS server-side scraping of every ad-library surface:
 * `/ad-library/home`, `/ad-library/?companyName=…`, and `/ad-library/search?…`
 * all return LinkedIn's multilingual 404 shell to Firecrawl `/scrape`, and the
 * plain company page returns 403. The ad-library is a login/bot-gated SPA whose
 * real content only loads client-side after auth the scraper can't satisfy. The
 * old `/scrape` path therefore stored the 404 error page (in ~12 languages) as
 * "ads" — pure noise.
 *
 * The working substrate is Firecrawl `/search` (the same endpoint that powers
 * market intel). It surfaces a competitor's PUBLIC LinkedIn footprint — company
 * page, posts, campaign copy, announcements — which is exactly the competitor
 * MESSAGING the CompetitorStrategistAgent synthesises (dominant themes,
 * positioning, whitespace). It is not formal "ad creative" (LinkedIn won't
 * expose that without auth), but it is real, verifiable competitor content
 * instead of 404 garbage.
 *
 * Best-effort: if Firecrawl fails or returns nothing, the agent continues with
 * whatever other intel it has rather than blocking the run.
 */
class LinkedinAdLibraryFirecrawlClient
{
    public function __construct(
        private readonly Http $http = new Http(['timeout' => 60, 'allow_redirects' => true]),
    ) {}

    /**
     * Fetch a competitor's public LinkedIn marketing content by company slug.
     * Returns rows in the shape CompetitorAdNormalizer::fromLinkedin expects
     * ({body, ad_url, landing_url, first_seen_date, ad_id, cta_text, image_url}),
     * so the downstream normaliser/upsert path is unchanged.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAdsForCompany(string $companySlug, int $limit = 25): array
    {
        $apiKey = config('services.firecrawl.api_key');
        if (! $apiKey) {
            Log::info('Firecrawl: no API key configured, skipping LinkedIn intel', [
                'company_slug' => $companySlug,
            ]);

            return [];
        }

        $base = rtrim((string) config('services.firecrawl.base_url'), '/');
        $timeout = (int) config('services.firecrawl.request_timeout', 60);

        // Scope the search to the competitor's LinkedIn presence so results are
        // their own messaging, not third-party mentions. The slug is the
        // linkedin.com/company/<slug> tail; humanise it for the free-text query
        // while also anchoring to the site.
        $company = str_replace('-', ' ', $companySlug);
        $query = sprintf('%s LinkedIn company posts marketing campaign site:linkedin.com', $company);

        try {
            $res = $this->http->post($base.'/search', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'query' => $query,
                    'limit' => max(1, min($limit, 20)),
                ],
                'timeout' => $timeout,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Firecrawl: LinkedIn competitor search failed', [
                'company_slug' => $companySlug,
                'error' => substr($e->getMessage(), 0, 240),
            ]);

            return [];
        }

        $payload = json_decode((string) $res->getBody(), true);
        $results = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if ($results === []) {
            return [];
        }

        $rows = [];
        foreach ($results as $r) {
            $url = trim((string) ($r['url'] ?? ''));
            $title = trim((string) ($r['title'] ?? ''));
            // Firecrawl search returns a short description/snippet per result;
            // the key name has varied ('description' | 'snippet' | 'content').
            $snippet = trim((string) ($r['description'] ?? ($r['snippet'] ?? ($r['content'] ?? ''))));

            // Only keep results that are actually on LinkedIn AND carry the
            // competitor's own content (skip bare directory/login shells).
            if ($url === '' || stripos($url, 'linkedin.com') === false) {
                continue;
            }

            // The "ad body" is the competitor's public messaging: title + snippet.
            $body = trim($title.($snippet !== '' ? "\n".$snippet : ''));
            if ($body === '') {
                continue;
            }

            $rows[] = [
                'body' => $body,
                'image_url' => '',
                'ad_url' => $url,
                'landing_url' => $url,
                'first_seen_date' => null,
                // Stable-ish id from the URL so re-runs dedupe on the same result.
                'ad_id' => substr(sha1($url), 0, 16),
                'cta_text' => '',
            ];

            if (count($rows) >= max(1, $limit)) {
                break;
            }
        }

        return $rows;
    }
}
