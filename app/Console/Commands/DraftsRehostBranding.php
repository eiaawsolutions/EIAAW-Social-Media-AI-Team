<?php

namespace App\Console\Commands;

use App\Agents\BaseAgent;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * drafts:rehost-branding — migrate drafts whose branded image/video URL points
 * at the ephemeral local `public` disk over to the durable disk (R2).
 *
 * Background ([[brand-asset-storage-ephemeral]], second occurrence): the
 * DesignerAgent/VideoAgent compositor paths used to publish the branded artifact
 * to the local `public` disk and store a `<APP_URL>/storage/branding/…` URL on
 * the draft. On Railway that disk is ephemeral and unserved (no storage:link),
 * so the URL 404s — the draft preview shows "Media preview unavailable" and
 * Metricool's publish-time normalize fetch fails too. The agents are now fixed
 * to publish to R2; this command repairs drafts created BEFORE that fix whose
 * bytes are still present on the current container's local disk.
 *
 * It only touches drafts whose asset_url is a local /storage/branding/ URL AND
 * whose underlying file is still readable. It re-publishes that file to the
 * durable disk, rewrites asset_url + asset_urls (replacing the dead URL while
 * preserving history), and reports anything it couldn't recover (bytes already
 * wiped → operator must regenerate that draft). Idempotent: a draft already on
 * the durable disk is skipped.
 *
 * URGENCY: the local bytes survive only until the next redeploy. Run this
 * BEFORE deploying the agent fix if you want to salvage existing scheduled
 * drafts; after a redeploy the local files are gone and affected drafts must be
 * regenerated instead.
 *
 * Usage:
 *   php artisan drafts:rehost-branding --dry-run     # report only, no writes
 *   php artisan drafts:rehost-branding               # migrate recoverable drafts
 *   php artisan drafts:rehost-branding --brand=1     # restrict to one brand
 */
class DraftsRehostBranding extends Command
{
    protected $signature = 'drafts:rehost-branding
        {--dry-run : Report what would change without writing}
        {--brand= : Restrict to a single brand id}';

    protected $description = 'Re-host stranded local /storage/branding/ draft media onto the durable disk (R2).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $targetDisk = BaseAgent::durableArtifactDisk();

        if ($targetDisk !== 'r2' && app()->isProduction()) {
            $this->error('Durable disk is not R2 in production (R2 bucket not configured). Aborting — fix R2 config first.');

            return self::FAILURE;
        }

        $query = Draft::query()
            ->where('asset_url', 'like', '%/storage/branding/%')
            ->orderBy('id');
        if ($brandId = $this->option('brand')) {
            $query->where('brand_id', (int) $brandId);
        }

        $drafts = $query->get();
        if ($drafts->isEmpty()) {
            $this->info('No drafts reference a local /storage/branding/ URL. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d draft(s) with local branding URLs → durable disk "%s".',
            $dryRun ? '[dry-run] would migrate' : 'Migrating',
            $drafts->count(),
            $targetDisk,
        ));

        $migrated = 0;
        $unrecoverable = [];
        $skipped = 0;

        foreach ($drafts as $draft) {
            $oldUrl = (string) $draft->asset_url;
            $rel = $this->relativeBrandingPath($oldUrl);
            if ($rel === null) {
                $skipped++;

                continue;
            }

            // Bytes must still be present on the local public disk.
            if (! Storage::disk('public')->exists($rel)) {
                $unrecoverable[] = $draft->id;

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  draft #%d: %s  →  (durable %s) %s', $draft->id, $oldUrl, $targetDisk, $rel));
                $migrated++;

                continue;
            }

            try {
                $bytes = Storage::disk('public')->get($rel);
                Storage::disk($targetDisk)->put($rel, $bytes, 'public');
                $newUrl = Storage::disk($targetDisk)->url($rel);

                $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
                $history = array_values(array_unique(array_merge(
                    array_filter($history, fn ($u) => $u !== $oldUrl),
                    [$newUrl],
                )));

                $draft->update([
                    'asset_url' => $newUrl,
                    'asset_urls' => $history,
                ]);

                $this->line(sprintf('  draft #%d migrated → %s', $draft->id, $newUrl));
                $migrated++;
            } catch (\Throwable $e) {
                $this->warn(sprintf('  draft #%d FAILED: %s', $draft->id, substr($e->getMessage(), 0, 160)));
                $unrecoverable[] = $draft->id;
            }
        }

        $this->newLine();
        $this->info(sprintf('%s: %d, skipped: %d, unrecoverable (bytes gone — regenerate): %d',
            $dryRun ? 'Would migrate' : 'Migrated', $migrated, $skipped, count($unrecoverable)));

        if ($unrecoverable !== []) {
            $this->warn('Unrecoverable draft ids (regenerate via Force AI image): '.implode(', ', $unrecoverable));
        }

        return self::SUCCESS;
    }

    /**
     * Extract the disk-relative path from a local /storage/branding/… URL.
     * Returns null if the URL isn't the local-branding shape.
     */
    private function relativeBrandingPath(string $url): ?string
    {
        $marker = '/storage/';
        $pos = strpos($url, $marker);
        if ($pos === false) {
            return null;
        }
        $rel = substr($url, $pos + strlen($marker));
        $rel = ltrim((string) parse_url($rel, PHP_URL_PATH) ?: $rel, '/');

        return str_starts_with($rel, 'branding/') ? $rel : null;
    }
}
