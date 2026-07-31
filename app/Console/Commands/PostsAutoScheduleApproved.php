<?php

namespace App\Console\Commands;

use App\Agents\StrategistAgent;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Models\GrowthStrategyBrief;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Services\Growth\BestTimeResolver;
use App\Services\Growth\GrowthPressure;
use App\Services\Growth\TimeSlotExplorer;
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
        /** @var array<string,int> $strategyTally how each time was chosen, for the run summary */
        $strategyTally = [];

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

                $strategy = null;
                $when = $this->resolveScheduledFor($draft, $brand, $fallbackOffset, $skippedPastFallback, $strategy);
                $strategyTally[$strategy] = ($strategyTally[$strategy] ?? 0) + 1;

                if ($dry) {
                    $this->line(sprintf(
                        '[dry] would schedule draft #%d (%s) brand=%d at %s UTC [%s]',
                        $draft->id, $draft->platform, $brand->id, $when->format('Y-m-d H:i'), $strategy,
                    ));
                    continue;
                }

                DB::transaction(function () use ($draft, $brand, $connection, $when, $strategy) {
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
                        'scheduling_strategy' => $strategy,
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
        if ($strategyTally !== []) {
            ksort($strategyTally);
            $this->line('time chosen by:            '.collect($strategyTally)
                ->map(fn ($n, $s) => "{$s}={$n}")
                ->implode(' '));
        }

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
    private function resolveScheduledFor(Draft $draft, \App\Models\Brand $brand, int $fallbackOffsetMinutes, int &$skippedPastFallback, ?string &$strategy = null): Carbon
    {
        $brandTz = $brand->timezone ?: 'UTC';
        $entry = $draft->calendarEntry;
        $now = Carbon::now('UTC');

        if ($entry && $entry->scheduled_date) {
            $datePart = Carbon::parse($entry->scheduled_date)->format('Y-m-d');
            $operatorPinned = trim((string) ($entry->scheduled_time ?? '')) !== '';
            // Operator's time wins outright. Only when unpinned do we consider
            // the computed best hour (else exploration / the legacy 09:00).
            if ($operatorPinned) {
                $timePart = trim((string) $entry->scheduled_time);
                $strategy = ScheduledPost::SCHEDULING_OPERATOR_PINNED;
            } else {
                [$timePart, $strategy] = $this->fallbackTimeFor($draft, $brand, $datePart, $brandTz);
            }
            // CalendarEntry.scheduled_time is stored as a TIME — combine with
            // date in the brand's TZ, then convert to UTC for storage.
            try {
                $when = Carbon::createFromFormat('Y-m-d H:i:s', "{$datePart} {$timePart}", $brandTz);
            } catch (\Throwable) {
                $when = Carbon::createFromFormat('Y-m-d H:i', "{$datePart} 09:00", $brandTz);
                $strategy = ScheduledPost::SCHEDULING_DEFAULT_FALLBACK;
            }
            $whenUtc = $when->copy()->setTimezone('UTC');

            if ($whenUtc->greaterThan($now)) {
                return $whenUtc;
            }
            // Past slot — fall through to "now + offset" so we don't bury it.
            $skippedPastFallback++;
        }

        // This hour records when our pipeline caught up, NOT when the audience
        // was there. Labelling it keeps it out of the best-time learner.
        $strategy = ScheduledPost::SCHEDULING_PAST_SLOT_FALLBACK;

        return $now->copy()->addMinutes($fallbackOffsetMinutes);
    }

    /**
     * The fallback time for an unpinned entry, plus the label describing how it
     * was chosen. Never touches an operator-set time.
     *
     * Three sources, in order:
     *   1. EXPLOIT — the brief's measured best hour for (platform, day-of-week),
     *      when best-times application is on for this brand (globally, or via L3
     *      pressure for a brand whose goal is far behind pace).
     *   2. EXPLORE — a sampled candidate hour, so the learner has variance to
     *      learn from. Without this arm every unpinned entry lands on the same
     *      hour and the learner reads back its own default forever.
     *   3. DEFAULT — the legacy 09:00.
     *
     * The draft id seeds the choice, so a --dry-run preview and the real write
     * agree, and a re-run picks the same hour.
     *
     * @return array{0:string,1:string}  [HH:MM:SS, strategy label]
     */
    private function fallbackTimeFor(Draft $draft, \App\Models\Brand $brand, string $datePart, string $brandTz): array
    {
        $brief = GrowthStrategyBrief::currentForBrand($brand->id)->first();

        // The measured hour is only admissible when best-time application is
        // enabled — globally, or for this brand because L3 pressure unlocked it.
        $exploitHour = null;
        if ($brief && is_array($brief->best_posting_times) && $this->bestTimesEnabledFor($brief)) {
            $dayOfWeek = (int) Carbon::parse($datePart, $brandTz)->dayOfWeek; // 0=Sun..6=Sat
            $exploitHour = BestTimeResolver::hourFor($brief->best_posting_times, $draft->platform, $dayOfWeek);
        }

        $epsilon = (bool) config('services.growth_strategy.time_exploration_enabled', true)
            ? (float) config('services.growth_strategy.time_exploration_epsilon', 0.30)
            : 0.0;

        $decision = TimeSlotExplorer::decide(
            platform: (string) $draft->platform,
            seed: (int) $draft->id,
            exploitHour: $exploitHour,
            epsilon: $epsilon,
        );

        return [sprintf('%02d:00:00', $decision['hour']), $decision['strategy']];
    }

    /**
     * May we apply the brief's MEASURED best hour for this brand?
     *
     * Globally off by default. L3 pressure turns it on per-brand: a goal far
     * enough behind pace has earned the schedule actuator, and only for as long
     * as it stays behind. Both switches are required for L3 — the ladder cannot
     * escalate itself past a flag an operator set.
     */
    private function bestTimesEnabledFor(GrowthStrategyBrief $brief): bool
    {
        if ((bool) config('services.growth_strategy.auto_apply_best_times', false)) {
            return true;
        }

        return (bool) config('services.growth_strategy.pressure_actuation_enabled', false)
            && StrategistAgent::maxRung((array) ($brief->goal_progress ?? [])) >= GrowthPressure::RUNG_SCHEDULE;
    }
}
