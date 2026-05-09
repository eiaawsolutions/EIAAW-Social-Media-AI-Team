<?php

namespace App\Services\Intel;

use App\Models\Brand;
use App\Models\PlatformConnection;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads competitor ads from the Meta Ad Library.
 *
 * Auth: reuses the brand's existing Meta PlatformConnection access token
 * (created at platform-connect time, encrypted at rest). No new OAuth flow.
 *
 * Per Meta API docs the Ad Library endpoint accepts a Page-scoped OR a
 * User-scoped token; the same token Blotato uses to publish on the brand's
 * connected Page is sufficient for read-only Ad Library queries against
 * any disclosed advertiser.
 *
 * Endpoint: GET /v20.0/ads_archive
 * Required params: search_page_ids OR search_terms, ad_reached_countries,
 * ad_active_status (we want 'ALL' so paused-but-recent ads still surface).
 */
class MetaAdLibraryClient
{
    public function __construct(
        private readonly Http $http = new Http(['timeout' => 30, 'allow_redirects' => true]),
    ) {}

    /**
     * Find the Meta access token for a brand. Returns null if the brand has
     * no active facebook OR instagram connection (either token works).
     */
    public function tokenForBrand(Brand $brand): ?string
    {
        $conn = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->whereIn('platform', ['facebook', 'instagram'])
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN platform = 'facebook' THEN 0 ELSE 1 END")
            ->first();

        if (! $conn) return null;

        try {
            return $conn->getAccessToken();
        } catch (\Throwable $e) {
            Log::warning('MetaAdLibrary: token decrypt failed', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch up to $limit recent ads for a single Page id in given geos.
     *
     * Returns raw Meta ad rows (not yet normalised). Caller passes them to
     * CompetitorAdNormalizer::fromMeta() for upsert into competitor_ads.
     *
     * @param string|null $accessToken    User/Page token from PlatformConnection
     * @param string      $pageId         Numeric Meta Page id
     * @param array       $geoCodes       ISO country codes — defaults to global
     * @param int         $limit          Max ads (capped at 100 by Meta API)
     * @return array<int,array<string,mixed>>
     */
    public function fetchAdsForPage(
        ?string $accessToken,
        string $pageId,
        array $geoCodes = [],
        int $limit = 25,
    ): array {
        if (! $accessToken) {
            Log::info('MetaAdLibrary: no access token, skipping fetch', ['page_id' => $pageId]);
            return [];
        }

        $base = (string) config('services.meta.ad_library_base_url');
        $countries = $geoCodes ?: ['ALL'];

        $params = [
            'search_page_ids' => $pageId,
            'ad_reached_countries' => json_encode(array_values($countries)),
            'ad_active_status' => 'ALL',
            'ad_type' => 'ALL',
            'fields' => implode(',', [
                'id',
                'ad_creation_time',
                'ad_creative_bodies',
                'ad_creative_link_captions',
                'ad_creative_link_titles',
                'ad_creative_link_descriptions',
                'ad_snapshot_url',
                'page_id',
                'page_name',
                'publisher_platforms',
                'languages',
                'target_locations',
                'ad_delivery_start_time',
                'ad_delivery_stop_time',
            ]),
            'limit' => min(100, max(1, $limit)),
            'access_token' => $accessToken,
        ];

        try {
            $res = $this->http->get($base, [
                'query' => $params,
                'timeout' => (int) config('services.meta.ad_library_request_timeout', 30),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MetaAdLibrary: request failed', [
                'page_id' => $pageId,
                'error' => substr($e->getMessage(), 0, 240),
            ]);
            return [];
        }

        $body = (string) $res->getBody();
        $json = json_decode($body, true);
        if (! is_array($json) || empty($json['data']) || ! is_array($json['data'])) {
            return [];
        }

        return $json['data'];
    }
}
