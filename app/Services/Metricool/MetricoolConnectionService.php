<?php

namespace App\Services\Metricool;

use App\Models\Brand;
use App\Models\PlatformConnection;
use App\Services\Readiness\SetupReadiness;
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

                // ── Why this is a two-step (revoke-then-upsert) and not a single
                //    updateOrCreate keyed on (brand, platform) ──────────────────
                //
                // The business invariant is "one ACTIVE connection per (brand,
                // platform)". But the DB unique index is the THREE columns
                // (brand_id, platform, platform_account_id) — see the brands
                // migration. Those two facts pull in opposite directions, and
                // each previous single-step attempt broke one of them:
                //
                //   • Keying updateOrCreate on the full 3-tuple → never matched a
                //     legacy Blotato row (Metricool reports a different handle,
                //     e.g. a Facebook page id vs the display name) → INSERTED a
                //     duplicate on every re-sync (the chips-doubled bug).
                //   • Keying updateOrCreate on (brand, platform) only → matches an
                //     ARBITRARY same-(brand,platform) row (in practice the lowest
                //     id, often a revoked legacy row) and rewrites its handle to
                //     the new value — which collides with the SIBLING row that
                //     already owns (brand, platform, newHandle) on the 3-col
                //     index → SQLSTATE 23505, surfaced to the customer as
                //     "Couldn't check right now" (the Re-check outage, 2026-06-07).
                //
                // The collision-proof shape: first clear every OTHER active row
                // for this (brand, platform) — legacy Blotato rows AND a genuinely
                // changed account both qualify — so nothing else can be holding
                // the target handle as ACTIVE; then upsert keyed on the FULL
                // 3-column key, which == the unique index. That match key can
                // neither create a duplicate (the index guarantees it) nor
                // collide (it IS the index), and it cleanly REACTIVATES a
                // previously-revoked row when the same account reconnects.

                // Step 1 — revoke any active row for this network that is NOT the
                // handle Metricool currently reports (stale legacy/previous
                // account). Leave an already-active matching-handle row alone.
                PlatformConnection::query()
                    ->where('brand_id', $brand->id)
                    ->where('platform', $platform)
                    ->where('platform_account_id', '!=', $handle)
                    ->where('status', 'active')
                    ->update(['status' => 'revoked']);

                // Step 2 — upsert keyed on the full unique key (brand, platform,
                // handle). Reuses the exact row for this account if it exists
                // (reactivating it), else inserts. No duplicate, no collision.
                PlatformConnection::updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'platform' => $platform,
                        'platform_account_id' => $handle,
                    ],
                    [
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

        // Connection state just changed — bust the 30s readiness cache so the
        // Setup Wizard's Stage 4 ("At least one platform connected") flips
        // immediately on the same request that ran the sync, instead of lagging
        // up to 30s behind. Every other state-changing surface (agents,
        // BrandCorpusSeed, AutonomyLane, CustomisedPostScheduler) invalidates
        // after a write; this is the connection seam all four sync callers
        // (ManagePlatformConnections, MetricoolSetup, MetricoolOnboarding,
        // BrandSetMetricoolBlog) funnel through, so invalidating here covers
        // them all at once. See [[account_growth_dashboard]] sibling fix.
        app(SetupReadiness::class)->invalidate($brand);

        return ['synced' => $synced, 'revoked' => $revoked, 'networks' => array_keys($result['networks'])];
    }
}
