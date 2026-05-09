<?php

namespace App\Services\Intel;

use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads competitor ads from the LinkedIn EU DSA Ad Transparency portal via
 * Firecrawl. LinkedIn has no public Ad Library API in 2026 — the portal at
 * https://www.linkedin.com/ad-library/ is the only structured surface.
 *
 * Coverage caveat: portal only shows ads served to EU users (DSA scope).
 * Non-EU campaigns are invisible. We document this limitation; the agent
 * still ships value because (a) most B2B competitors run pan-EU campaigns
 * and (b) Meta Ad Library covers the rest.
 *
 * Best-effort: if Firecrawl fails or returns nothing, the agent continues
 * with Meta-only intel rather than blocking the whole run.
 */
class LinkedinAdLibraryFirecrawlClient
{
    public function __construct(
        private readonly Http $http = new Http(['timeout' => 60, 'allow_redirects' => true]),
    ) {}

    /**
     * Fetch the LinkedIn ad library page for a company slug.
     *
     * @return array<int,array<string,mixed>>  Normalised-ish ad rows
     *   {body, asset_url, ad_url, first_seen_at, source_ad_id}
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

        // LinkedIn Ad Library URL pattern — by company slug.
        // Verified live 2026-Q2. The portal accepts /ad-library/?companyName=<slug>
        // and returns a paginated grid; Firecrawl's `extract` mode pulls the
        // structured fields cleanly without us hand-rolling DOM selectors.
        $targetUrl = sprintf(
            'https://www.linkedin.com/ad-library/?companyName=%s',
            urlencode($companySlug),
        );

        $base = rtrim((string) config('services.firecrawl.base_url'), '/');
        $timeout = (int) config('services.firecrawl.request_timeout', 60);

        try {
            $res = $this->http->post($base.'/scrape', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'url' => $targetUrl,
                    'formats' => ['extract'],
                    'extract' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'ads' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'body' => ['type' => 'string'],
                                            'image_url' => ['type' => 'string'],
                                            'ad_url' => ['type' => 'string'],
                                            'first_seen_date' => ['type' => 'string'],
                                            'ad_id' => ['type' => 'string'],
                                            'cta_text' => ['type' => 'string'],
                                            'landing_url' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'systemPrompt' => 'Extract every ad creative shown on this LinkedIn Ad Library page. Each ad must include the body copy, primary image URL if present, the ad detail page URL, the disclosed first-seen date, the LinkedIn ad id from the URL, the call-to-action text, and the landing URL.',
                    ],
                ],
                'timeout' => $timeout,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Firecrawl: LinkedIn ad library fetch failed', [
                'company_slug' => $companySlug,
                'error' => substr($e->getMessage(), 0, 240),
            ]);
            return [];
        }

        $payload = json_decode((string) $res->getBody(), true);
        if (! is_array($payload) || ! isset($payload['data']['extract']['ads'])) {
            return [];
        }

        $ads = $payload['data']['extract']['ads'];
        if (! is_array($ads)) return [];

        return array_slice($ads, 0, max(1, $limit));
    }
}
