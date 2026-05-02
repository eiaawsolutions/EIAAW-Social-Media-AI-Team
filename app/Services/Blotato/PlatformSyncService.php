<?php

namespace App\Services\Blotato;

use App\Models\Brand;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sync the connected social accounts from Blotato into our local
 * platform_connections table.
 *
 * Why: customers connect platforms inside Blotato's web UI (we don't have
 * an OAuth-redirect flow for that — Blotato has done App Review for each
 * platform; we read the resulting state). On click of "Sync from Blotato"
 * in /agency/platforms, we:
 *
 *   1. List all accounts from Blotato (`GET /v2/users/me/accounts`).
 *   2. Upsert one PlatformConnection row per (brand, platform, blotato_account_id).
 *   3. Mark rows that disappeared from Blotato as `status = revoked`
 *      (don't delete — preserves historical attribution + ScheduledPost
 *      audit trail).
 *
 * Brand association: Blotato accounts are workspace-level in Blotato's
 * model; our schema is brand-level. The caller specifies which brand the
 * sync is for; we upsert against (brand_id, platform, blotato_account_id).
 * One Blotato account can therefore be linked to multiple brands in our
 * DB if the customer uses one social handle across brands — that's
 * intentional.
 */
class PlatformSyncService
{
    public function __construct(private readonly BlotatoClient $blotato) {}

    /**
     * Sync all Blotato accounts into platform_connections for the given brand.
     *
     * @return array{synced: int, marked_revoked: int, errors: array<string>}
     */
    public function syncForBrand(Brand $brand): array
    {
        if (! $this->blotato->ping()) {
            return [
                'synced' => 0,
                'marked_revoked' => 0,
                'errors' => ['Could not reach Blotato. Verify BLOTATO_API_KEY at eiaaw-smt-prod/prod/BLOTATO_API_KEY.'],
            ];
        }

        try {
            $accounts = $this->blotato->listAccounts();
        } catch (\Throwable $e) {
            Log::error('PlatformSyncService::syncForBrand listAccounts failed', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'synced' => 0,
                'marked_revoked' => 0,
                'errors' => ['Blotato API error: ' . $e->getMessage()],
            ];
        }

        $synced = 0;
        $errors = [];
        $seenBlotatoIds = [];

        DB::transaction(function () use ($brand, $accounts, &$synced, &$errors, &$seenBlotatoIds) {
            foreach ($accounts as $acct) {
                $blotatoId = (string) ($acct['id'] ?? '');
                $platform = $this->normalizePlatform((string) ($acct['platform'] ?? ''));
                if ($blotatoId === '' || $platform === null) {
                    $errors[] = 'Skipped malformed account: ' . json_encode($acct);
                    continue;
                }
                $seenBlotatoIds[] = $blotatoId;

                $username = (string) ($acct['username'] ?? '');
                $fullname = (string) ($acct['fullname'] ?? '');

                PlatformConnection::updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'platform' => $platform,
                        'platform_account_id' => $blotatoId,
                    ],
                    [
                        'display_handle' => $username !== '' ? ltrim($username, '@') : $fullname,
                        'blotato_account_id' => $blotatoId,
                        // Tokens are managed by Blotato — we don't store them
                        // ourselves. Keep the columns but write empty encrypted
                        // strings so the NOT NULL constraint holds.
                        'access_token_encrypted' => $this->emptyEncrypted(),
                        'refresh_token_encrypted' => null,
                        'token_expires_at' => null,
                        'scopes' => null,
                        'status' => 'active',
                    ],
                );
                $synced++;
            }

            // Mark any previously-active connections that are no longer in
            // Blotato's account list as revoked. Don't delete rows — they
            // anchor ScheduledPost audit records and platform-side post IDs.
            $marked = PlatformConnection::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->whereNotIn('blotato_account_id', $seenBlotatoIds === [] ? [''] : $seenBlotatoIds)
                ->update(['status' => 'revoked']);

            $errors[] = null; // sentinel
            $errors = array_filter($errors);
            $errors[] = "marked_revoked={$marked}";
        });

        // Pull the marked count back out of the sentinel
        $markedRevoked = 0;
        $clean = [];
        foreach ($errors as $e) {
            if (str_starts_with($e, 'marked_revoked=')) {
                $markedRevoked = (int) substr($e, strlen('marked_revoked='));
            } else {
                $clean[] = $e;
            }
        }

        Log::info('PlatformSyncService synced', [
            'brand_id' => $brand->id,
            'synced' => $synced,
            'marked_revoked' => $markedRevoked,
            'errors' => $clean,
        ]);

        return [
            'synced' => $synced,
            'marked_revoked' => $markedRevoked,
            'errors' => $clean,
        ];
    }

    /**
     * Map Blotato's platform enum onto our platform_connections.platform enum.
     * Returns null for platforms we don't model yet.
     *
     * Blotato: twitter | instagram | linkedin | facebook | tiktok | pinterest
     *        | threads | bluesky | youtube | other
     * Ours:    instagram | facebook | linkedin | tiktok | threads | x | youtube | pinterest
     */
    private function normalizePlatform(string $blotatoPlatform): ?string
    {
        return match ($blotatoPlatform) {
            'twitter' => 'x', // Blotato still labels X as "twitter"
            'instagram' => 'instagram',
            'linkedin' => 'linkedin',
            'facebook' => 'facebook',
            'tiktok' => 'tiktok',
            'pinterest' => 'pinterest',
            'threads' => 'threads',
            'youtube' => 'youtube',
            'bluesky', 'other' => null, // not modeled — skip silently for v1
            default => null,
        };
    }

    /**
     * platform_connections.access_token_encrypted is NOT NULL in the schema.
     * Under the Blotato model we don't hold customer tokens (Blotato does),
     * so write a sentinel encrypted-empty-string for that column. If we ever
     * migrate to first-party OAuth in v2, real tokens overwrite this.
     */
    private function emptyEncrypted(): string
    {
        return \Illuminate\Support\Facades\Crypt::encryptString('');
    }
}
