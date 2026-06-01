<?php

namespace App\Console\Commands;

use App\Jobs\SubmitScheduledPost;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cron entry: every minute, dispatch a SubmitScheduledPost job for every
 * scheduled_posts row whose scheduled_for has passed and is in a state that
 * still needs work:
 *   - status=queued                     → first submission
 *   - status=submitted (no post id)     → poll for a stuck-processing row
 *   - status=failed AND attempt_count<3 → automatic retry with 5-min backoff
 *   - status=submitting (stale >10min)  → orphan recovery (worker died mid-submit)
 *
 * The Job itself is idempotent so re-dispatching the same row is safe; we
 * still rate-limit per row by skipping rows that updated in the last 60s
 * (poll) or 5min (failed retry).
 *
 * Orphan recovery (added after the 2026-06-01 "stuck on submitting" incident):
 * SubmitScheduledPost sets status='submitting' BEFORE the provider call, then
 * advances to 'submitted'/'failed' after. If the worker dies in between (queue
 * $timeout, OOM, or an uncatchable fatal — see [[worker_timeout_contract]]),
 * the row is orphaned: it's no longer 'queued' (skipped by branch 1), has no
 * provider id so it isn't 'submitted' (branch 2), and never reached 'failed'
 * (branch 3). Nothing recovered it and it showed "WAIT…(auto)" forever. Branch 4
 * re-queues any 'submitting' row untouched for >10min — comfortably beyond the
 * job's 120s $timeout, so a genuinely in-flight job is never interrupted. Rows
 * WITH a provider id are NOT reset (they may have actually submitted); they fall
 * to the poll path once they reach 'submitted'.
 */
class PostsDispatchDue extends Command
{
    protected $signature = 'posts:dispatch-due {--limit=100}';
    protected $description = 'Dispatch SubmitScheduledPost jobs for queued/retry-eligible scheduled posts.';

    public function handle(): int
    {
        $now = Carbon::now();
        $limit = (int) $this->option('limit');
        $dispatched = 0;

        // 1. Queued + due.
        $rows = ScheduledPost::where('status', 'queued')
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();
        foreach ($rows as $row) {
            SubmitScheduledPost::dispatch($row->id)->onQueue('publishing');
            $dispatched++;
        }

        // 2. Submitted but no platform_post_id yet — poll if last touched > 60s ago.
        $polls = ScheduledPost::where('status', 'submitted')
            ->whereNull('platform_post_id')
            ->where('updated_at', '<=', $now->copy()->subSeconds(60))
            ->limit($limit)
            ->get();
        foreach ($polls as $row) {
            SubmitScheduledPost::dispatch($row->id)->onQueue('publishing');
            $dispatched++;
        }

        // 3. Failed + retryable + last attempt > 5 min ago.
        $retries = ScheduledPost::where('status', 'failed')
            ->where('attempt_count', '<', 3)
            ->where('updated_at', '<=', $now->copy()->subMinutes(5))
            ->limit($limit)
            ->get();
        foreach ($retries as $row) {
            // Bring it back to queued so the Job's normal entry path runs.
            $row->update(['status' => 'queued']);
            SubmitScheduledPost::dispatch($row->id)->onQueue('publishing');
            $dispatched++;
        }

        // 4. Orphan recovery: rows stuck in 'submitting' for >10min whose worker
        // died before advancing them. Only those WITHOUT a provider id are safe
        // to re-queue (they never submitted, so no double-post risk); ones with
        // an id are left for the poll path. Bounded by attempt_count: under 3 →
        // re-queue for a clean retry; at/over 3 → mark failed so it surfaces in
        // the live feed as actionable instead of silently stuck forever.
        $orphans = ScheduledPost::where('status', 'submitting')
            ->whereNull('blotato_post_id')
            ->where('updated_at', '<=', $now->copy()->subMinutes(10))
            ->limit($limit)
            ->get();
        $orphansRequeued = 0;
        $orphansFailed = 0;
        foreach ($orphans as $row) {
            if ($row->attempt_count < 3) {
                $row->update(['status' => 'queued']);
                SubmitScheduledPost::dispatch($row->id)->onQueue('publishing');
                $orphansRequeued++;
                $dispatched++;
            } else {
                $row->update([
                    'status' => 'failed',
                    'last_error' => 'Stuck in submitting after 3 attempts (worker died mid-submit before the provider replied). '
                        . 'Click Retry to try again, or check worker health.',
                ]);
                $orphansFailed++;
            }
        }

        Log::info('posts:dispatch-due tick', [
            'dispatched' => $dispatched,
            'queued_due' => $rows->count(),
            'polls' => $polls->count(),
            'retries' => $retries->count(),
            'orphans_requeued' => $orphansRequeued,
            'orphans_failed' => $orphansFailed,
        ]);
        $this->info("Dispatched {$dispatched} job(s).");
        return self::SUCCESS;
    }
}
