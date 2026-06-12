<?php

namespace App\Console\Commands;

use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Models\GrowthStrategyBrief;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Services\Growth\BestTimeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The missing auto-scheduler. Closes the loop between the autonomy lane
 * comment ("green = auto-publish") and the Schedule page actually filling.
 *
 * Picks every Draft that is:
 *   - status = 'approved'   (green lane lands here automatically post-Compliance;
 *                            amber/red gets here when a human approves)
 *   - has no live ScheduledPost yet (queued/submitting/submitted/published)
 *   - has an active platform_connection for its target platform
 *
 * Computes scheduled_for from the linked CalendarEntry's scheduled_date +
 * scheduled_time interpreted in the brand's timezone. Falls back to
 * now + 10 minutes if the entry has no time / is in the past — operator
 * still gets a publish, just queued instead of pinned.
 *
 * Idempotent: if the row already exists, we skip. Safe to run every minute.
 */
class PostsAutoScheduleApproved extends Command
{
    protected $signature = 'posts:auto-schedule-approved
                            {--limit=200 : max drafts to schedule per run}
                            {--dry-run : list what would be scheduled, do not write}
                            {--fallback-offset=10 : minutes from now() when no calendar time pins it}';

    protected $description = 'Turn approved Drafts into queued ScheduledPost rows. Closes the auto-publish loop.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');
        $fallbackOffset = max(1, (int) $this->option('fallback-offset'));

        $drafts = Draft::query()
            ->with(['brand:id,timezone,workspace_id', 'calendarEntry:id,scheduled_date,scheduled_time'])
            ->where('status', 'approved')
            ->whereDoesntHave('scheduledPosts', function ($q) {
                $q->whereIn('status', ['queued', 'submitting', 'submitted', 'published']);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('Nothing to auto-schedule.');
            return self::SUCCESS;
        }

        $scheduled = 0;
        $skippedNoConnection = 0;
        $skippedPastFallback = 0;
        $errors = 0;

        foreach ($drafts as $draft) {
            try {
                $brand = $draft->brand;
                if (! $brand) {
                    $skippedNoConnection++;
                    continue;
                }

                $connection = PlatformConnection::where('brand_id', $brand->id)
                    ->where('platform', $draft->platform)
                    ->where('status', 'active')
                    ->first();

                if (! $connection) {
                    $skippedNoConnection++;
                    $this->warn("Draft #{$draft->id} ({$draft->platform}): no active connection for brand #{$brand->id}; skipping.");
                    continue;
                }

                $when = $this->resolveScheduledFor($draft, $brand, $fallbackOffset, $skippedPastFallback);

                if ($dry) {
                    $this->line(sprintf(
                        '[dry] would schedule draft #%d (%s) brand=%d at %s UTC',
                        $draft->id, $draft->platform, $brand->id, $when->format('Y-m-d H:i'),
                    ));
                    continue;
                }

                DB::transaction(function () use ($draft, $brand, $connection, $when) {
                    // Race-safe re-check inside the transaction — another worker
                    // could have created the row between our SELECT and INSERT.
                    $existing = ScheduledPost::where('draft_id', $draft->id)
                        ->whereIn('status', ['queued', 'submitting', 'submitted', 'published'])
                        ->lockForUpdate()
                        ->exists();
                    if ($existing) {
                        return;
                    }

                    ScheduledPost::create([
                        'draft_id' => $draft->id,
                        'brand_id' => $brand->id,
                        'platform_connection_id' => $connection->id,
                        'scheduled_for' => $when,
                        'status' => 'queued',
                        'attempt_count' => 0,
                    ]);
                    $draft->update(['status' => 'scheduled']);
                });

                $scheduled++;
                $this->info(sprintf(
                    'Scheduled draft #%d (%s) brand=%d for %s UTC',
                    $draft->id, $draft->platform, $brand->id, $when->format('Y-m-d H:i'),
                ));
            } catch (\Throwable $e) {
                $errors++;
                Log::error('PostsAutoScheduleApproved: error scheduling draft', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Draft #{$draft->id}: {$e->getMessage()}");
            }
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line("scheduled:                 {$scheduled}");
        $this->line("skipped (no connection):   {$skippedNoConnection}");
        $this->line("fell back to now+offset:   {$skippedPastFallback}");
        $this->line("errors:                    {$errors}");

        return self::SUCCESS;
    }

    /**
     * Resolve scheduled_for from the calendar entry, in brand TZ, then convert
     * to UTC for storage. If the calendar slot is already in the past (or
     * absent), use now() + fallback-offset minutes so we still publish.
     *
     * Best-time override: when the operator did NOT pin a scheduled_time, the
     * hardcoded 09:00 fallback is replaced by the brand's computed best hour for
     * that (platform, day-of-week) from its GrowthStrategyBrief — but ONLY when
     * config('services.growth_strategy.auto_apply_best_times') is on. An
     * operator-pinned scheduled_time is NEVER overridden.
     *
     * @param-out int $skippedPastFallback incremented when fallback path used
     */
    private function resolveScheduledFor(Draft $draft, \App\Models\Brand $brand, int $fallbackOffsetMinutes, int &$skippedPastFallback): Carbon
    {
        $brandTz = $brand->timezone ?: 'UTC';
        $entry = $draft->calendarEntry;
        $now = Carbon::now('UTC');

        if ($entry && $entry->scheduled_date) {
            $datePart = Carbon::parse($entry->scheduled_date)->format('Y-m-d');
            $operatorPinned = trim((string) ($entry->scheduled_time ?? '')) !== '';
            // Operator's time wins outright. Only when unpinned do we consider
            // the computed best hour (else the legacy 09:00 default).
            $timePart = $operatorPinned
                ? trim((string) $entry->scheduled_time)
                : $this->fallbackTimeFor($draft, $brand, $datePart, $brandTz);
            // CalendarEntry.scheduled_time is stored as a TIME — combine with
            // date in the brand's TZ, then convert to UTC for storage.
            try {
                $when = Carbon::createFromFormat('Y-m-d H:i:s', "{$datePart} {$timePart}", $brandTz);
            } catch (\Throwable) {
                $when = Carbon::createFromFormat('Y-m-d H:i', "{$datePart} 09:00", $brandTz);
            }
            $whenUtc = $when->copy()->setTimezone('UTC');

            if ($whenUtc->greaterThan($now)) {
                return $whenUtc;
            }
            // Past slot — fall through to "now + offset" so we don't bury it.
            $skippedPastFallback++;
        }

        return $now->copy()->addMinutes($fallbackOffsetMinutes);
    }

    /**
     * The fallback time (HH:MM:SS) to use when the operator didn't pin one.
     * Legacy default is 09:00. When auto-apply-best-times is enabled and the
     * brand's GrowthStrategyBrief has a best hour for this (platform, day), use
     * that hour instead. Pure-fallback path — touches no operator-set time.
     */
    private function fallbackTimeFor(Draft $draft, \App\Models\Brand $brand, string $datePart, string $brandTz): string
    {
        if (! (bool) config('services.growth_strategy.auto_apply_best_times', false)) {
            return '09:00:00';
        }

        $brief = GrowthStrategyBrief::currentForBrand($brand->id)->first();
        if (! $brief || ! is_array($brief->best_posting_times)) {
            return '09:00:00';
        }

        $dayOfWeek = (int) Carbon::parse($datePart, $brandTz)->dayOfWeek; // 0=Sun..6=Sat
        $hour = BestTimeResolver::hourFor($brief->best_posting_times, $draft->platform, $dayOfWeek);
        if ($hour === null) {
            return '09:00:00';
        }

        return sprintf('%02d:00:00', $hour);
    }
}
