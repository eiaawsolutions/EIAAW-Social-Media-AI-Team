<?php

namespace App\Console\Commands;

use App\Agents\ComplianceAgent;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot recovery command for the May 2026 prod incident: 12 of 18
 * queued ScheduledPosts had calendar entries asking for media (single_image
 * / carousel / reel / video / story) on text-permitting platforms (LinkedIn
 * / Threads / Facebook), but the linked drafts had asset_url=null. Without
 * a calendar-format-aware media gate they would publish text-only,
 * silently dropping the visual the operator scheduled.
 *
 * What this does for each affected SP:
 *   1. Cancel the SP (so posts:dispatch-due skips it).
 *   2. Reset draft.status to compliance_pending.
 *   3. Re-run ComplianceAgent — the new gate fires
 *      `calendar_format_media_missing` and flips the draft to
 *      compliance_failed.
 *   4. The minute-cadence drafts:redraft-failed cron picks the failed
 *      drafts up via the existing regenerate_media route (Designer +
 *      VideoAgent for video formats). Once an asset lands and Compliance
 *      passes, posts:auto-schedule-approved creates a fresh SP.
 *
 * Defaults to dry-run; --apply commits.
 */
class PostsBackfillMediaIntent extends Command
{
    protected $signature = 'posts:backfill-media-intent
                            {--limit=200}
                            {--apply : actually write changes (default is dry-run)}';

    protected $description = 'Cancel queued SPs whose drafts lack the calendar-required asset, then re-run Compliance so the redraft loop fixes them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = ScheduledPost::with(['draft.calendarEntry', 'brand'])
            ->where('status', 'queued')
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $affected = $candidates->filter(function (ScheduledPost $sp) {
            $entry = $sp->draft?->calendarEntry;
            $format = strtolower((string) ($entry->format ?? ''));
            $isMediaFormat = in_array($format, ['single_image', 'carousel', 'reel', 'video', 'story'], true);
            return $isMediaFormat && empty($sp->draft?->asset_url);
        });

        $this->info(sprintf('Scanned %d queued SPs; %d need recovery.', $candidates->count(), $affected->count()));

        if ($affected->isEmpty()) return self::SUCCESS;

        $stats = ['cancelled' => 0, 'recompliance_ok' => 0, 'recompliance_failed' => 0, 'errors' => 0];

        foreach ($affected as $sp) {
            $d = $sp->draft;
            $line = sprintf('SP%d %s fmt=%s draft#%d', $sp->id, $d->platform, $d->calendarEntry?->format ?? '?', $d->id);

            if (! $apply) {
                $this->line('[dry] would recover '.$line);
                continue;
            }

            try {
                DB::transaction(function () use ($sp, $d) {
                    $sp->update([
                        'status' => 'cancelled',
                        'last_error' => 'Cancelled by posts:backfill-media-intent on '.now()->toIso8601String()
                            .' — calendar format requires media that draft lacks. Compliance + redraft loop will regenerate.',
                    ]);
                    $d->update(['status' => 'compliance_pending']);
                });
                $stats['cancelled']++;
            } catch (\Throwable $e) {
                $this->warn($line.' DB update failed: '.substr($e->getMessage(), 0, 120));
                $stats['errors']++;
                continue;
            }

            // Re-run Compliance now so the new gate fires immediately and
            // the draft sits in compliance_failed by the next minute's
            // drafts:redraft-failed cron tick.
            try {
                app(ComplianceAgent::class)->run($sp->brand, ['draft_id' => $d->id]);
                $d->refresh();
                if ($d->status === 'compliance_failed') {
                    $stats['recompliance_failed']++;
                    $this->info($line.' → cancelled + draft now compliance_failed (redraft will regen).');
                } else {
                    $stats['recompliance_ok']++;
                    $this->warn($line.' → cancelled but draft.status='.$d->status
                        .' (gate did not fire — check calendar entry format).');
                }
            } catch (\Throwable $e) {
                $this->warn($line.' ComplianceAgent crashed: '.substr($e->getMessage(), 0, 120));
                $stats['errors']++;
            }
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line('cancelled SPs:           '.$stats['cancelled']);
        $this->line('drafts → compliance_failed: '.$stats['recompliance_failed']);
        $this->line('drafts still passing:    '.$stats['recompliance_ok']);
        $this->line('errors:                  '.$stats['errors']);
        if (! $apply) $this->warn('DRY-RUN — re-run with --apply.');
        else $this->info('drafts:redraft-failed cron (every 5 min) will regenerate media on the failed drafts.');

        return self::SUCCESS;
    }
}
