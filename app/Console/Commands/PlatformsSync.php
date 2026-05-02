<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Blotato\BlotatoClient;
use App\Services\Blotato\PlatformSyncService;
use Illuminate\Console\Command;

/**
 * platforms:sync — sync connected social accounts from Blotato into our
 * platform_connections table for a single brand.
 *
 * The Filament UI (/agency/platforms) calls the same PlatformSyncService —
 * this command is the same logic exposed as artisan for ops + smoke testing.
 *
 * Usage:
 *   php artisan platforms:sync --brand=12
 *   php artisan platforms:sync --brand=12 --dry-run     (just probe Blotato + print accounts, no DB writes)
 */
class PlatformsSync extends Command
{
    protected $signature = 'platforms:sync {--brand=} {--dry-run}';

    protected $description = 'Sync connected social accounts from Blotato into platform_connections for a brand.';

    public function handle(): int
    {
        $brandId = (int) $this->option('brand');
        if ($brandId <= 0) {
            $this->error('--brand=<id> is required');
            return self::FAILURE;
        }

        $brand = Brand::find($brandId);
        if (! $brand) {
            $this->error("No brand with id {$brandId}.");
            return self::FAILURE;
        }

        $this->info("Brand: #{$brand->id} ({$brand->slug}) — workspace #{$brand->workspace_id}");

        $blotato = BlotatoClient::fromConfig();

        $this->line('Pinging Blotato...');
        if (! $blotato->ping()) {
            $this->error('Blotato unreachable. Check BLOTATO_API_KEY at eiaaw-smt-prod/prod/BLOTATO_API_KEY.');
            return self::FAILURE;
        }
        $this->info('  Blotato OK.');

        if ($this->option('dry-run')) {
            $this->line('Listing accounts (dry-run, no DB writes)...');
            try {
                $accounts = $blotato->listAccounts();
            } catch (\Throwable $e) {
                $this->error('  listAccounts failed: ' . $e->getMessage());
                return self::FAILURE;
            }
            $this->info("  Found " . count($accounts) . ' account(s):');
            foreach ($accounts as $a) {
                $this->line(sprintf('    [%s] %s — %s (%s)',
                    $a['platform'] ?? '?',
                    $a['id'] ?? '?',
                    $a['fullname'] ?? '?',
                    $a['username'] ?? '?',
                ));
            }
            $this->newLine();
            $this->comment('Dry-run only. Re-run without --dry-run to upsert into platform_connections.');
            return self::SUCCESS;
        }

        $sync = new PlatformSyncService($blotato);
        $result = $sync->syncForBrand($brand);

        $this->newLine();
        $this->info("Synced: {$result['synced']} account(s)");
        $this->info("Marked revoked: {$result['marked_revoked']}");
        if ($result['errors']) {
            $this->warn('Errors:');
            foreach ($result['errors'] as $err) {
                $this->line('  - ' . $err);
            }
        }

        $this->newLine();
        $this->info('Current platform_connections for this brand:');
        $rows = \App\Models\PlatformConnection::where('brand_id', $brand->id)
            ->orderBy('platform')
            ->get();
        if ($rows->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['id', 'platform', 'handle', 'blotato_id', 'status'],
                $rows->map(fn ($r) => [
                    $r->id,
                    $r->platform,
                    $r->display_handle ?? '-',
                    $r->blotato_account_id ?? '-',
                    $r->status,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
