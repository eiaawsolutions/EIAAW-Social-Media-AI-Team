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
 *
 * The Job itself is idempotent so re-dispatching the same row is safe; we
 * still rate-limit per row by skipping rows that updated in the last 60s
 * (poll) or 5min (failed retry).
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

        Log::info('posts:dispatch-due tick', [
            'dispatched' => $dispatched,
            'queued_due' => $rows->count(),
            'polls' => $polls->count(),
            'retries' => $retries->count(),
        ]);
        $this->info("Dispatched {$dispatched} job(s).");
        return self::SUCCESS;
    }
}
