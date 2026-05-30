<?php

namespace App\Services\Metricool;

use App\Models\Brand;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detects which social networks a brand has connected inside Metricool, and
 * mirrors them into our platform_connections table — the Metricool equivalent
 * of Blotato's PlatformSyncService::listAccounts().
 *
 * Why this exists: in the onboarding flow we generate a Metricool share-link,
 * the customer connects their socials inside Metricool (we can't OAuth them
 * ourselves), and then we DETECT the result by reading /admin/profile. That
 * profile is a flat object whose per-network keys hold the connected account
 * handle when connected and null when not — verified live 2026-05-30:
 *   instagram: "eiaawsolutions", linkedinCompany: "urn:li:person:…",
 *   facebookPageId: "1179788…", tiktok: "eiaawsolutions", youtube: "UC…",
 *   threads: "eiaawsolutions"; twitter/pinterest/bluesky: null.
 *
 * Targeting is by (brand metricool_blog_id, network) — there is no per-account
 * id like Blotato's blotato_account_id, so platform_connections rows carry the
 * network handle as platform_account_id and an empty token (Metricool holds
 * the tokens, like Blotato did).
 */
class MetricoolConnectionService
{
    public function __construct(private readonly MetricoolClient $client) {}

    /**
     * The Metricool /admin/profile field that signals each of OUR platform
     * enums is connected. Value present (non-null, non-empty) = connected.
     * We probe a couple of fallbacks per network to be resilient to Metricool's
     * naming (e.g. linkedin lives under linkedinCompany).
     *
     * @var array<string, array<int,string>>
     */
    private const NETWORK_FIELDS = [
        'instagram' => ['instagram'],
        'facebook' => ['facebookPageId', 'facebook'],
        'linkedin' => ['linkedinCompany', 'linkedin'],
        'tiktok' => ['tiktok'],
        'youtube' => ['youtube'],
        'pinterest' => ['pinterest'],
        'threads' => ['threads'],
        'x' => ['twitter'],
        'bluesky' => ['bluesky'],
    ];

    /**
     * Read the brand's Metricool profile and return the list of connected
     * networks (our platform enums) with their handles.
     *
     * @return array{found:bool, networks:array<string,string>, raw:array<string,mixed>}
     *         found=false when the brand has no blogId or the profile 404s.
     *         networks maps platform enum => connected handle/id.
     */
    public function detect(Brand $brand): array
    {
        $blogId = $brand->metricool_blog_id;
        if (! $blogId) {
            return ['found' => false, 'networks' => [], 'raw' => []];
        }

        try {
            $profile = $this->client->getProfile((int) $blogId, refreshCache: true);
        } catch (\Throwable $e) {
            Log::warning('MetricoolConnectionService::detect getProfile failed', [
                'brand_id' => $brand->id,
                'blog_id' => $blogId,
                'error' => $e->getMessage(),
            ]);
            return ['found' => false, 'networks' => [], 'raw' => []];
        }

        if (! ($profile['found'] ?? false)) {
            return ['found' => false, 'networks' => [], 'raw' => []];
        }

        $body = $profile['body'];
        $networks = [];
        foreach (self::NETWORK_FIELDS as $platform => $fields) {
            foreach ($fields as $field) {
                $val = $body[$field] ?? null;
                if (is_scalar($val) && (string) $val !== '' && $val !== false) {
                    $networks[$platform] = (string) $val;
                    break;
                }
            }
        }

        return ['found' => true, 'networks' => $networks, 'raw' => $body];
    }

    /**
     * Detect + mirror into platform_connections. Upserts one active row per
     * connected (brand, network); marks rows whose network is no longer
     * connected as 'revoked' (so a disconnect in Metricool is reflected).
     *
     * @return array{synced:int, revoked:int, networks:array<int,string>}
     */
    public function sync(Brand $brand): array
    {
        $result = $this->detect($brand);
        if (! $result['found']) {
            return ['synced' => 0, 'revoked' => 0, 'networks' => []];
        }

        $synced = 0;
        $seen = [];

        DB::transaction(function () use ($brand, $result, &$synced, &$seen) {
            foreach ($result['networks'] as $platform => $handle) {
                $seen[] = $platform;
                // Key the upsert on (brand_id, platform) ONLY — the business
                // invariant is one active connection per network per brand.
                // The platform_account_id (handle) is NOT part of the match key:
                // Metricool reports a different handle than the legacy Blotato
                // rows did (e.g. a Facebook page id vs the display name), so
                // keying on it would never find the existing row and would
                // insert a duplicate on every re-sync. Instead we update the
                // handle in place as a value below.
                $kept = PlatformConnection::updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'platform' => $platform,
                    ],
                    [
                        'platform_account_id' => $handle,
                        'display_handle' => ltrim($handle, '@'),
                        // No per-account id in Metricool — targeting is by
                        // brand blogId + network. Keep blotato_account_id null.
                        'blotato_account_id' => null,
                        // Metricool holds the tokens; we store empty (the
                        // column is NOT NULL, like the Blotato path did).
                        'access_token_encrypted' => Crypt::encryptString(''),
                        'refresh_token_encrypted' => null,
                        'token_expires_at' => null,
                        'scopes' => null,
                        'status' => 'active',
                    ],
                );

                // Self-heal pre-existing duplicates: if any OTHER active row
                // exists for this (brand, platform) — e.g. a leftover legacy
                // Blotato-era row from before the migration, which carries a
                // non-null blotato_account_id and a different handle — revoke it
                // so exactly one active connection per network survives.
                PlatformConnection::query()
                    ->where('brand_id', $brand->id)
                    ->where('platform', $platform)
                    ->whereKeyNot($kept->getKey())
                    ->where('status', 'active')
                    ->update(['status' => 'revoked']);

                $synced++;
            }
        });

        // Revoke connections for networks Metricool no longer reports for this
        // brand. Now that a single row per (brand, platform) is canonical, this
        // covers legacy Blotato rows too (the per-platform self-heal above
        // already collapsed any duplicates for still-connected networks).
        $revoked = PlatformConnection::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->when($seen !== [], fn ($q) => $q->whereNotIn('platform', $seen))
            ->update(['status' => 'revoked']);

        return ['synced' => $synced, 'revoked' => $revoked, 'networks' => array_keys($result['networks'])];
    }
}
