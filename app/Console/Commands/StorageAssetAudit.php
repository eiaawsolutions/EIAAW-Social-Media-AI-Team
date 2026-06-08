<?php

namespace App\Console\Commands;

use App\Models\BrandAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * storage:asset-audit — read-only report of brand assets whose underlying file
 * is missing from storage.
 *
 * Background ([[brand-asset-storage-ephemeral]]): assets uploaded to the
 * ephemeral local `public` disk were wiped on redeploy, leaving rows whose
 * public_url is well-formed but whose bytes are gone — the preview renders
 * blank. This audits which rows are affected (per brand / workspace) so we can
 * tell each customer exactly what to re-upload once durable R2 storage is live.
 *
 * It does a disk stat per asset, so it's intended for ad-hoc / scheduled runs,
 * not a hot path. It NEVER mutates anything.
 *
 * Usage:
 *   php artisan storage:asset-audit                 # audit all non-archived assets
 *   php artisan storage:asset-audit --brand=8       # only brand #8
 *   php artisan storage:asset-audit --missing-only  # list only the broken ones
 *   php artisan storage:asset-audit --include-archived
 */
class StorageAssetAudit extends Command
{
    protected $signature = 'storage:asset-audit
        {--brand= : Restrict to a single brand id}
        {--missing-only : Only print assets whose file is missing}
        {--include-archived : Include archived assets}';

    protected $description = 'Report brand assets whose underlying storage file is missing (broken previews).';

    public function handle(): int
    {
        $query = BrandAsset::query()->with('brand')->orderBy('brand_id')->orderBy('id');

        if (! $this->option('include-archived')) {
            $query->whereNull('archived_at');
        }
        if ($brandId = $this->option('brand')) {
            $query->where('brand_id', (int) $brandId);
        }

        $assets = $query->get();
        if ($assets->isEmpty()) {
            $this->info('No brand assets match the filter.');
            return self::SUCCESS;
        }

        $missing = [];
        $present = 0;
        $byBrand = [];

        foreach ($assets as $asset) {
            $ok = $asset->bytesAvailable();
            if ($ok) {
                $present++;
            } else {
                $missing[] = $asset;
                $key = ($asset->brand?->name ?? 'brand#' . $asset->brand_id)
                    . ' (ws#' . ($asset->brand?->workspace_id ?? '?') . ')';
                $byBrand[$key] = ($byBrand[$key] ?? 0) + 1;
            }

            if (! $this->option('missing-only') || ! $ok) {
                $this->line(sprintf(
                    '  [%s] asset#%d brand=%s disk=%s %s',
                    $ok ? '✓' : '✗',
                    $asset->id,
                    $asset->brand_id,
                    $asset->storage_disk ?: '?',
                    $asset->original_filename ?: $asset->storage_path,
                ));
            }
        }

        $this->newLine();
        $this->info('──────── Summary ────────');
        $this->line("  assets scanned : {$assets->count()}");
        $this->line("  file present   : {$present}");
        $this->line('  file MISSING   : ' . count($missing));

        if ($missing !== []) {
            $this->newLine();
            $this->warn('Affected (re-upload needed once durable storage is live):');
            foreach ($byBrand as $brand => $count) {
                $this->line("  • {$brand}: {$count} asset(s)");
            }
            // Non-zero exit so this can gate a health check, but it's a data
            // condition (recoverable by re-upload), not a code bug.
            return self::FAILURE;
        }

        $this->info('✓ All scanned assets have their files in storage.');
        return self::SUCCESS;
    }
}
