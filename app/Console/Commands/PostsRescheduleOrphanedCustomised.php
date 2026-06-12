<?php

namespace App\Console\Commands;

use App\Exceptions\AlreadyScheduledException;
use App\Models\BrandAsset;
use App\Models\PlatformConnection;
use App\Services\Imagery\CustomisedPostScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recovery for customised-post assets whose scheduling never completed.
 *
 * A "Customised post" upload persists the BrandAsset FIRST, then runs
 * CustomisedPostScheduler::schedule() inside a transaction. If that transaction
 * rolled back (e.g. the historical SQLSTATE 23502 model_id / pillar_mix NOT-NULL
 * crashes), the asset is left stamped usage_intent='customised' but with a NULL
 * customised_calendar_entry_id — i.e. no calendar entry, no draft, no scheduled
 * post. The operator sees it sitting in the Asset library but it never appears
 * on Calendar / Schedule / Live feed.
 *
 * This command finds those orphans and re-runs the (now-fixed) scheduler so they
 * enter the normal Draft → Compliance → ScheduledPost → publish rail. Orphans
 * never captured platforms / narrative / publish date, so we accept sensible
 * inputs and fall back to the asset's own metadata.
 *
 * Idempotent: an asset that already has a calendar entry carries a non-null
 * customised_calendar_entry_id and is excluded by the query, so re-running is
 * safe. --dry-run lists what would be rescheduled without writing.
 */
class PostsRescheduleOrphanedCustomised extends Command
{
    protected $signature = 'posts:reschedule-orphaned-customised
                            {--asset= : limit to a single BrandAsset id}
                            {--workspace= : limit to assets whose brand belongs to this workspace id (tenant scope)}
                            {--platforms= : comma-separated platforms; defaults to the asset\'s scheduled_platforms, else instagram}
                            {--narrative= : caption override; defaults to the asset\'s description}
                            {--publish-in-minutes=30 : minutes from now() to publish (brand timezone)}
                            {--dry-run : list what would be rescheduled, do not write}';

    protected $description = 'Re-run the customised-post scheduler for orphaned customised assets (uploaded but never scheduled).';

    public function handle(CustomisedPostScheduler $scheduler): int
    {
        $dry = (bool) $this->option('dry-run');
        $minutes = max(1, (int) $this->option('publish-in-minutes'));
        $platformsOpt = trim((string) ($this->option('platforms') ?? ''));
        $narrativeOpt = trim((string) ($this->option('narrative') ?? ''));
        $workspaceId = $this->option('workspace') ? (int) $this->option('workspace') : null;

        $assets = BrandAsset::query()
            ->where('usage_intent', BrandAsset::INTENT_CUSTOMISED)
            ->whereNull('customised_calendar_entry_id')
            ->whereNull('archived_at')
            ->when($this->option('asset'), fn ($q, $id) => $q->where('id', (int) $id))
            // Tenant scope: this is a CLI with no auth/workspace context, so the
            // unfiltered query would span ALL customers. --workspace bounds it to
            // one tenant's brands (BrandAsset → Brand.workspace_id). Without it the
            // command processes every workspace's orphans — fine for a one-off
            // global sweep, but --workspace lets an operator target one customer.
            ->when($workspaceId, fn ($q, $ws) => $q->whereHas(
                'brand',
                fn ($b) => $b->where('workspace_id', $ws),
            ))
            ->orderBy('id')
            ->get();

        if ($workspaceId) {
            $this->info("Scoped to workspace #{$workspaceId}.");
        } else {
            $this->warn('No --workspace given: sweeping orphaned customised assets across ALL workspaces.');
        }

        if ($assets->isEmpty()) {
            $this->info('No orphaned customised assets to reschedule.');
            return self::SUCCESS;
        }

        $rescheduled = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($assets as $asset) {
            $brand = $asset->brand;
            if (! $brand) {
                $skipped++;
                $this->warn("Asset #{$asset->id}: brand not found; skipping.");
                continue;
            }

            // Platforms: explicit option > the asset's stored choice > instagram.
            $rawPlatforms = $platformsOpt !== ''
                ? explode(',', $platformsOpt)
                : (is_array($asset->scheduled_platforms) && $asset->scheduled_platforms !== []
                    ? $asset->scheduled_platforms
                    : ['instagram']);
            $platforms = CustomisedPostScheduler::normalisePlatforms($rawPlatforms);
            if ($platforms === []) {
                $skipped++;
                $this->warn("Asset #{$asset->id}: no valid platforms; skipping.");
                continue;
            }

            // Narrative: explicit option > the asset's vision description.
            $narrative = $narrativeOpt !== '' ? $narrativeOpt : trim((string) ($asset->description ?? ''));
            if ($narrative === '') {
                $skipped++;
                $this->warn("Asset #{$asset->id}: no narrative (pass --narrative or tag the asset); skipping.");
                continue;
            }

            // Pre-flight: warn about platforms with no active connection. The
            // scheduler will still create the draft, but posts:auto-schedule-
            // approved silently skips a draft whose platform has no active
            // connection — so the post would sit 'approved' and never queue.
            // Surface that here instead of leaving it a silent dead-end.
            $activePlatforms = PlatformConnection::where('brand_id', $brand->id)
                ->where('status', 'active')
                ->pluck('platform')
                ->all();
            $missingConn = array_values(array_diff($platforms, $activePlatforms));
            if ($missingConn !== []) {
                $this->warn(sprintf(
                    'Asset #%d: no active connection for [%s] — those posts will be created but won\'t auto-queue until connected.',
                    $asset->id, implode(',', $missingConn),
                ));
            }

            $brandTz = $brand->timezone ?: 'UTC';
            $publishAt = Carbon::now($brandTz)->addMinutes($minutes);

            $line = sprintf(
                'asset #%d brand=%d [%s] @ %s (%s)',
                $asset->id, $brand->id, implode(',', $platforms),
                $publishAt->format('Y-m-d H:i'), $brandTz,
            );

            if ($dry) {
                $this->line("[dry] would reschedule {$line}");
                continue;
            }

            try {
                $result = $scheduler->schedule(
                    asset: $asset,
                    brand: $brand,
                    narrative: $narrative,
                    platforms: $platforms,
                    publishAt: $publishAt,
                    narrativeSource: $asset->narrative_source ?: 'manual',
                    hashtags: null,
                );
                $rescheduled++;
                $this->info(sprintf('Rescheduled %s — %d draft(s).', $line, count($result['drafts'])));
            } catch (AlreadyScheduledException $e) {
                // Benign: another worker (or a prior run) already scheduled it.
                $skipped++;
                $this->line("Asset #{$asset->id}: already scheduled; skipping.");
            } catch (\Throwable $e) {
                $errors++;
                Log::error('PostsRescheduleOrphanedCustomised: failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Asset #{$asset->id}: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line("rescheduled: {$rescheduled}");
        $this->line("skipped:     {$skipped}");
        $this->line("errors:      {$errors}");

        return self::SUCCESS;
    }
}
