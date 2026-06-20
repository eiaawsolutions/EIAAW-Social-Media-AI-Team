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
 * Two modes:
 *
 *   (a) BYTE-COPY MIGRATE (default) — for drafts whose underlying file is still
 *       readable on the local public disk (dev, or before the next redeploy):
 *       re-publish that file to the durable disk and rewrite asset_url +
 *       asset_urls (replacing the dead URL while preserving history).
 *
 *   (b) STRIP-DEAD (--strip-dead) — for the real-world post-redeploy case where
 *       the bytes are GONE (the URL 404s): just remove every /storage/branding/
 *       entry from the asset_urls HISTORY so it stops poisoning previews,
 *       thumbnails, and PlatformRules::countMedia. If the PRIMARY asset_url is
 *       itself a dead ephemeral URL it is reported as "needs regeneration"
 *       (run drafts:regenerate-image <id>) — never nulled blindly.
 *
 * Why both: the 2026-06-20 failures had a clean durable asset_url but dead
 * ephemeral URLs hiding inside asset_urls — the old query (asset_url LIKE only)
 * missed them entirely. We now also match the JSON history (asset_urls::text)
 * so those drafts are found. Idempotent: a clean draft is skipped.
 *
 * URGENCY (byte-copy mode only): local bytes survive only until the next
 * redeploy. Run before deploying the agent fix to salvage drafts; after a
 * redeploy use --strip-dead and regenerate the unrecoverable primaries.
 *
 * Usage:
 *   php artisan drafts:rehost-branding --dry-run                 # report only
 *   php artisan drafts:rehost-branding                          # byte-copy migrate
 *   php artisan drafts:rehost-branding --strip-dead --dry-run    # report strip plan
 *   php artisan drafts:rehost-branding --strip-dead              # strip dead history
 *   php artisan drafts:rehost-branding --brand=1                 # restrict to one brand
 */
class DraftsRehostBranding extends Command
{
    protected $signature = 'drafts:rehost-branding
        {--dry-run : Report what would change without writing}
        {--strip-dead : Remove dead /storage/branding/ URLs from asset_urls history (use after redeploy when bytes are gone)}
        {--brand= : Restrict to a single brand id}';

    protected $description = 'Re-host or strip stranded local /storage/branding/ draft media (durable disk / history cleanup).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stripDead = (bool) $this->option('strip-dead');
        $targetDisk = BaseAgent::durableArtifactDisk();

        // Strip mode only deletes dead history entries — it never writes to the
        // durable disk, so it doesn't need R2 configured. Byte-copy mode does.
        if (! $stripDead && $targetDisk !== 'r2' && app()->isProduction()) {
            $this->error('Durable disk is not R2 in production (R2 bucket not configured). Aborting — fix R2 config first.');

            return self::FAILURE;
        }

        // Match drafts polluted in EITHER the primary asset_url OR anywhere in
        // the asset_urls JSON history — the 2026-06-20 failures had a clean
        // durable primary but dead ephemeral URLs hiding only in the history,
        // which the old asset_url-only query missed entirely. asset_urls is
        // Postgres json; cast to text to substring-match it.
        $query = Draft::query()
            ->where(function ($q) {
                $q->where('asset_url', 'like', '%/storage/branding/%')
                    ->orWhereRaw("asset_urls::text LIKE '%/storage/branding/%'");
            })
            ->orderBy('id');
        if ($brandId = $this->option('brand')) {
            $query->where('brand_id', (int) $brandId);
        }

        $drafts = $query->get();
        if ($drafts->isEmpty()) {
            $this->info('No drafts reference a local /storage/branding/ URL. Nothing to do.');

            return self::SUCCESS;
        }

        if ($stripDead) {
            return $this->stripDeadHistory($drafts, $dryRun);
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
     * Strip dead /storage/branding/ URLs out of each draft's asset_urls history
     * (the bytes are gone — this is cleanup, not migration). Reports drafts whose
     * PRIMARY asset_url is itself a dead ephemeral URL as needing regeneration;
     * those are never nulled here.
     *
     * @param  \Illuminate\Support\Collection<int,Draft>  $drafts
     */
    private function stripDeadHistory(\Illuminate\Support\Collection $drafts, bool $dryRun): int
    {
        $this->info(sprintf(
            '%s dead /storage/branding/ URLs from asset_urls history of %d draft(s).',
            $dryRun ? '[dry-run] would strip' : 'Stripping',
            $drafts->count(),
        ));

        $stripped = 0;
        $cleanPrimary = 0;
        $needsRegeneration = [];

        foreach ($drafts as $draft) {
            $primaryDead = str_contains((string) $draft->asset_url, '/storage/branding/');
            if ($primaryDead) {
                $needsRegeneration[] = $draft->id;
            }

            $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
            $cleaned = array_values(array_filter(
                $history,
                fn ($u) => is_string($u) && $u !== '' && ! str_contains($u, '/storage/branding/'),
            ));

            $removed = count($history) - count($cleaned);
            if ($removed === 0) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  draft #%d: would drop %d dead history URL(s)%s',
                    $draft->id, $removed, $primaryDead ? ' [PRIMARY also dead — regenerate]' : ''));
                $stripped++;

                continue;
            }

            $draft->update(['asset_urls' => $cleaned]);
            if (! $primaryDead) {
                $cleanPrimary++;
            }
            $this->line(sprintf('  draft #%d: dropped %d dead history URL(s)%s',
                $draft->id, $removed, $primaryDead ? ' [PRIMARY also dead — regenerate]' : ''));
            $stripped++;
        }

        $this->newLine();
        $this->info(sprintf('%s history on %d draft(s); %d had a clean durable primary.',
            $dryRun ? 'Would strip' : 'Stripped', $stripped, $cleanPrimary));

        if ($needsRegeneration !== []) {
            $this->warn('Drafts whose PRIMARY asset_url is dead — regenerate each:');
            foreach ($needsRegeneration as $id) {
                $this->warn(sprintf('  php artisan drafts:regenerate-image %d', $id));
            }
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
