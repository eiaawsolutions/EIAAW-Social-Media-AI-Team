<?php

namespace App\Console\Commands;

use App\Models\BrandAsset;
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

        $assets = BrandAsset::query()
            ->where('usage_intent', BrandAsset::INTENT_CUSTOMISED)
            ->whereNull('customised_calendar_entry_id')
            ->whereNull('archived_at')
            ->when($this->option('asset'), fn ($q, $id) => $q->where('id', (int) $id))
            ->orderBy('id')
            ->get();

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
