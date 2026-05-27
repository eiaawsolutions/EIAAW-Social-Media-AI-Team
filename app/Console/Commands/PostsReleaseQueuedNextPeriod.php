<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * posts:release-queued-next-period — flip `queued_next_period` rows back to
 * `queued` once their `queued_for_period_at` has passed.
 *
 * SubmitScheduledPost defers any post that hits the monthly published-posts
 * cap to status='queued_next_period' with a target wall-clock time set to
 * first-of-next-month at 00:05 workspace TZ. This command, scheduled to
 * run hourly via Kernel::schedule(), is the release valve: any row whose
 * target time has arrived gets flipped back to 'queued' so the regular
 * dispatch poller picks it up like a normal scheduled post.
 *
 * Defence in depth: this command does NOT re-check the cap. By design, a
 * row deferred from October cap should release in November regardless of
 * November's cap state — November gets its own cap to spend. If November
 * is also at-cap when the row dispatches, SubmitScheduledPost re-defers
 * to December. That's intentional: prevents indefinite suppression but
 * keeps the customer's content flowing through.
 *
 * Idempotent: re-running mid-hour is safe (rows already flipped don't
 * re-flip).
 */
class PostsReleaseQueuedNextPeriod extends Command
{
    protected $signature = 'posts:release-queued-next-period
                            {--dry-run : Show what would be released, don\'t actually write}';

    protected $description = 'Flip queued_next_period scheduled_posts back to queued once their release time has passed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $q = ScheduledPost::query()
            ->where('status', 'queued_next_period')
            ->whereNotNull('queued_for_period_at')
            ->where('queued_for_period_at', '<=', $now);

        $count = $q->count();
        $this->info("Found {$count} row(s) ready for release at {$now->toIso8601String()}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $rows = $q->limit(20)->get(['id', 'brand_id', 'queued_for_period_at']);
            foreach ($rows as $r) {
                $this->line(sprintf('  SP%d brand=%d release_at=%s', $r->id, $r->brand_id, $r->queued_for_period_at?->toIso8601String() ?? '?'));
            }
            if ($count > 20) {
                $this->line('  …and ' . ($count - 20) . ' more');
            }
            $this->warn('DRY-RUN — no changes made.');
            return self::SUCCESS;
        }

        // Flip in a single UPDATE so we don't race the dispatcher. Clear
        // last_error (was "Plan cap reached…") and queued_for_period_at
        // (we don't need it once released).
        $flipped = $q->update([
            'status' => 'queued',
            'queued_for_period_at' => null,
            'last_error' => null,
        ]);

        Log::info('PostsReleaseQueuedNextPeriod: released rows', ['count' => $flipped]);
        $this->info("Released {$flipped} row(s) to status=queued.");

        return self::SUCCESS;
    }
}
