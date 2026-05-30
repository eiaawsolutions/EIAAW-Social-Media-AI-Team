<?php

namespace App\Console\Commands;

use App\Agents\StrategistAgent;
use App\Jobs\DraftCalendarEntry;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Billing\PlanCaps;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Content autopilot — the missing front-half of the autonomous loop.
 *
 * The back half already runs on cron (posts:auto-schedule-approved →
 * posts:dispatch-due → SubmitScheduledPost → metrics:collect). But nothing
 * was triggering the *generation* side (Strategist → Writer → Designer →
 * Video → Compliance), so it only ran when an operator clicked a button in
 * the Setup wizard or the Calendar page. This command closes that gap: it
 * keeps every eligible brand supplied with fresh, compliance-gated drafts,
 * every day, with no human in the loop — until the operator clicks Stop.
 *
 * ── The autonomy contract this command enforces ──────────────────────────
 *
 *  1. STOP SWITCH (master kill).  A workspace with `publishing_paused = true`
 *     is skipped entirely — no calendar build, no drafting. This reuses the
 *     existing operator-facing "Stop publishing" / "Resume publishing"
 *     control on /agency/scheduled-posts. Nothing here is a new switch.
 *
 *  2. APPROVER GATING (where required).  We DO NOT decide approval here. The
 *     Writer stamps each draft with the brand's autonomy lane
 *     (Brand::defaultLaneFor — per-platform override, else global default,
 *     else 'amber'), and Compliance turns that into a status:
 *        green → 'approved'           (autonomous; auto-schedules + publishes)
 *        amber → 'awaiting_approval'  (waits for ONE human approval)
 *     So: if the operator has set at least one brand/platform to amber, those
 *     drafts sit in the Drafts queue waiting for a human — exactly where
 *     approval is required. Everything green runs fully autonomous. The
 *     downstream posts:auto-schedule-approved cron only ever picks up
 *     'approved' drafts, so amber is honoured by construction.
 *
 *  3. PLAN-CAP-BOUNDED VOLUME.  Posting volume is governed by the plan, not
 *     by how much the AI feels like generating. We bound new drafting per
 *     workspace by `remaining monthly post allowance − in-flight queued
 *     posts − drafts already awaiting scheduling`, so we never manufacture
 *     more than the plan will ever publish this month. SubmitScheduledPost
 *     remains the hard enforcement point (it defers over-cap posts to
 *     next period); this is just the polite upstream throttle that stops us
 *     burning FAL image/video budget on drafts that would never publish.
 *
 *  4. EVERY DAY, NON-STOP.  Scheduled hourly (see bootstrap/app.php). Each
 *     run is idempotent and incremental: it tops the calendar back up and
 *     drafts only what's missing. There is no end date — it runs until the
 *     operator pauses the workspace.
 *
 * Idempotency / safety:
 *   - Strategist is only invoked when calendar coverage for the upcoming
 *     window is thin (it always creates a brand-new month, so calling it
 *     blindly would spawn duplicate calendars).
 *   - DraftCalendarEntry is itself idempotent (no-ops if a draft already
 *     exists for the (entry, platform) pair), so re-dispatching is harmless.
 *   - All work is dispatched to the dedicated `autopilot` queue so it never
 *     starves the higher-priority `publishing` queue.
 */
class ContentAutopilot extends Command
{
    protected $signature = 'content:autopilot
                            {--brand= : restrict to a single brand id (debugging)}
                            {--coverage-days=10 : ensure the calendar has entries at least this many days out}
                            {--min-coverage=4 : run the Strategist when fewer than this many upcoming entries remain}
                            {--max-drafts-per-brand=12 : ceiling on (entry,platform) jobs dispatched per brand per run}
                            {--dry-run : report what would happen, dispatch nothing, write nothing}';

    protected $description = 'Keep every eligible brand supplied with fresh, compliance-gated drafts — autonomously, plan-capped, honouring approval lanes and the Stop-publishing switch.';

    public function handle(PlanCaps $caps): int
    {
        $dry = (bool) $this->option('dry-run');
        $coverageDays = max(1, (int) $this->option('coverage-days'));
        $minCoverage = max(1, (int) $this->option('min-coverage'));
        $perBrandCeiling = max(1, (int) $this->option('max-drafts-per-brand'));
        $onlyBrand = $this->option('brand') ? (int) $this->option('brand') : null;

        $now = Carbon::now('UTC');

        $brandQuery = Brand::query()
            ->whereNull('archived_at')
            ->with('workspace');
        if ($onlyBrand) {
            $brandQuery->where('id', $onlyBrand);
        }
        $brands = $brandQuery->orderBy('id')->get();

        if ($brands->isEmpty()) {
            $this->info('Autopilot: no active brands.');
            return self::SUCCESS;
        }

        $totals = [
            'brands_seen' => 0,
            'skipped_paused' => 0,
            'skipped_no_access' => 0,
            'skipped_at_cap' => 0,
            'calendars_built' => 0,
            'drafts_dispatched' => 0,
        ];

        foreach ($brands as $brand) {
            $totals['brands_seen']++;
            $workspace = $brand->workspace;

            if (! $workspace instanceof Workspace) {
                continue; // orphan brand; nothing we can safely do
            }

            // ── Guardrail 1: the master Stop switch. ──────────────────────
            if ($workspace->publishing_paused) {
                $totals['skipped_paused']++;
                $this->line("brand #{$brand->id} ({$brand->name}): workspace publishing PAUSED — skipping.");
                continue;
            }

            // A workspace whose subscription has lapsed shouldn't keep
            // generating billable AI work. EIAAW-internal always passes.
            if (! $workspace->hasActiveAccess()) {
                $totals['skipped_no_access']++;
                $this->line("brand #{$brand->id} ({$brand->name}): workspace has no active access — skipping.");
                continue;
            }

            // ── Guardrail 3: plan-cap budget for this workspace this run. ──
            $budget = $this->draftBudgetFor($caps, $workspace, $perBrandCeiling);
            if ($budget <= 0) {
                $totals['skipped_at_cap']++;
                $this->line("brand #{$brand->id} ({$brand->name}): at/near monthly post cap or enough in-flight — no new drafts this run.");
                continue;
            }

            // ── Calendar coverage: build a fresh month if we're running thin. ─
            $upcoming = $this->upcomingEntryCount($brand, $now, $coverageDays);
            if ($upcoming < $minCoverage) {
                if ($dry) {
                    $this->line("brand #{$brand->id} ({$brand->name}): [dry] coverage {$upcoming}<{$minCoverage} — would run Strategist.");
                } else {
                    $built = $this->buildCalendar($brand);
                    if ($built) {
                        $totals['calendars_built']++;
                        $this->info("brand #{$brand->id} ({$brand->name}): Strategist built a new calendar.");
                    }
                }
            }

            // ── Draft generation: dispatch idempotent per-(entry,platform) jobs. ─
            $dispatched = $this->dispatchDrafts($brand, $now, $coverageDays, $budget, $dry);
            $totals['drafts_dispatched'] += $dispatched;

            if ($dispatched > 0) {
                $this->info(sprintf(
                    'brand #%d (%s): %s %d draft job(s) (budget was %d).',
                    $brand->id, $brand->name, $dry ? '[dry] would dispatch' : 'dispatched', $dispatched, $budget,
                ));
            }
        }

        $this->line('');
        $this->line('--- autopilot summary'.($dry ? ' (DRY RUN)' : '').' ---');
        foreach ($totals as $k => $v) {
            $this->line(str_pad($k, 22).": {$v}");
        }

        Log::info('ContentAutopilot run complete', $totals + ['dry_run' => $dry]);

        return self::SUCCESS;
    }

    /**
     * How many new (entry, platform) draft jobs this workspace may spawn this
     * run, bounded by the plan. We start from the remaining monthly published-
     * post allowance, subtract work already in flight (queued ScheduledPosts +
     * drafts that are generated-but-not-yet-scheduled), and clamp to the
     * per-brand per-run ceiling. This keeps us from manufacturing drafts the
     * plan will never publish.
     */
    private function draftBudgetFor(PlanCaps $caps, Workspace $workspace, int $perBrandCeiling): int
    {
        $remaining = $caps->remainingPostAllowance($workspace);
        if ($remaining <= 0) {
            return 0;
        }

        $brandIds = $workspace->brands()->whereNull('archived_at')->pluck('id');
        if ($brandIds->isEmpty()) {
            return 0;
        }

        // Posts already queued/submitting/submitted but not yet published.
        $inFlightPosts = ScheduledPost::whereIn('brand_id', $brandIds)
            ->whereIn('status', ['queued', 'submitting', 'submitted'])
            ->count();

        // Drafts already generated and either auto-approved or awaiting a human
        // — they're "spent" against the allowance but not yet a ScheduledPost.
        // (compliance_pending is excluded: it may still fail and never ship.)
        $pendingDrafts = \App\Models\Draft::whereIn('brand_id', $brandIds)
            ->whereIn('status', ['approved', 'awaiting_approval'])
            ->count();

        $budget = $remaining - $inFlightPosts - $pendingDrafts;

        return max(0, min($perBrandCeiling, $budget));
    }

    /**
     * Count upcoming calendar entries (today .. today+coverageDays, brand TZ)
     * that still represent publishable plan — i.e. not archived/rejected at
     * the entry level. We don't require them to be undrafted here; this is the
     * "is the plan running out" signal that decides whether to call Strategist.
     */
    private function upcomingEntryCount(Brand $brand, Carbon $nowUtc, int $coverageDays): int
    {
        $tz = $brand->timezone ?: 'UTC';
        $today = Carbon::now($tz)->startOfDay();
        $until = $today->copy()->addDays($coverageDays)->endOfDay();

        return CalendarEntry::where('brand_id', $brand->id)
            ->whereBetween('scheduled_date', [$today->toDateString(), $until->toDateString()])
            ->count();
    }

    /**
     * Run the Strategist to build a fresh month. Soft-fails: a brand missing
     * prerequisites (no brand voice, no active platform connection) simply
     * doesn't get a calendar this run — logged, not fatal. The Strategist
     * itself validates prerequisites and returns a failed AgentResult rather
     * than throwing for the common cases.
     */
    private function buildCalendar(Brand $brand): bool
    {
        try {
            $result = app(StrategistAgent::class)->run($brand);
            if (! $result->ok) {
                Log::info('ContentAutopilot: Strategist did not build a calendar', [
                    'brand_id' => $brand->id,
                    'reason' => $result->errorMessage,
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('ContentAutopilot: Strategist crashed', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * For each upcoming calendar entry, dispatch one DraftCalendarEntry job
     * per (entry, platform) pair that has no draft yet — up to the budget.
     * DraftCalendarEntry is idempotent, so even if two autopilot runs overlap
     * (they shouldn't — withoutOverlapping guards the cron) the duplicate
     * job no-ops. Jobs go on the dedicated `autopilot` queue.
     *
     * @return int number of jobs dispatched (or, in dry-run, that would be)
     */
    private function dispatchDrafts(Brand $brand, Carbon $nowUtc, int $coverageDays, int $budget, bool $dry): int
    {
        $tz = $brand->timezone ?: 'UTC';
        $today = Carbon::now($tz)->startOfDay();
        $until = $today->copy()->addDays($coverageDays)->endOfDay();

        // Only target platforms the brand actually has an active connection
        // for — drafting for a disconnected platform produces a draft that
        // can never auto-schedule (PostsAutoScheduleApproved skips it).
        $activePlatforms = $brand->platformConnections()
            ->where('status', 'active')
            ->pluck('platform')
            ->map(fn ($p) => (string) $p)
            ->unique()
            ->values()
            ->all();

        if (empty($activePlatforms)) {
            return 0;
        }

        $entries = CalendarEntry::where('brand_id', $brand->id)
            ->whereBetween('scheduled_date', [$today->toDateString(), $until->toDateString()])
            ->with('drafts:id,calendar_entry_id,platform,status')
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();

        $dispatched = 0;

        foreach ($entries as $entry) {
            if ($dispatched >= $budget) {
                break;
            }

            $entryPlatforms = is_array($entry->platforms) ? $entry->platforms : [];
            // Intersect entry's planned platforms with the brand's connected
            // ones; preserve the entry's ordering (the first is the pillar
            // master that DraftCalendarEntry fans out from).
            $targets = array_values(array_filter(
                $entryPlatforms,
                fn ($p) => is_string($p) && in_array($p, $activePlatforms, true),
            ));

            foreach ($targets as $platform) {
                if ($dispatched >= $budget) {
                    break;
                }

                // Idempotent gate (mirrors DraftCalendarEntry's own check, so
                // we don't even enqueue a job that would no-op): skip if a
                // non-rejected draft already exists for this (entry, platform).
                $hasDraft = $entry->drafts
                    ->where('platform', $platform)
                    ->whereNotIn('status', ['rejected'])
                    ->isNotEmpty();
                if ($hasDraft) {
                    continue;
                }

                if (! $dry) {
                    DraftCalendarEntry::dispatch($entry->id, $platform)->onQueue('autopilot');
                }
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
