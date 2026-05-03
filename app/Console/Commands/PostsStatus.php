<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only health probe for the publish pipeline. Answers the question
 * "is the scheduled post posted?" without poking Blotato — it just inspects
 * the local scheduled_posts state machine + the kill switch.
 *
 *   php artisan posts:status                    # whole DB, last 24h
 *   php artisan posts:status --workspace=12     # one workspace
 *   php artisan posts:status --window=72        # widen window to 72h
 */
class PostsStatus extends Command
{
    protected $signature = 'posts:status {--workspace=} {--window=24}';
    protected $description = 'Read-only summary of scheduled-post pipeline health (counts by status + last published + stuck rows + kill-switch).';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $windowHours = max(1, (int) $this->option('window'));
        $since = Carbon::now()->subHours($windowHours);

        $base = ScheduledPost::query()
            ->when($workspaceId, fn ($q) => $q->whereHas('brand', fn ($b) => $b->where('workspace_id', $workspaceId)));

        $this->line('');
        $this->line("== Scheduled-post pipeline health (window: last {$windowHours}h) ==");
        if ($workspaceId) $this->line("   Workspace filter: {$workspaceId}");
        $this->line('');

        // Status breakdown across the whole table (not windowed — we want
        // backlog + history visibility).
        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $this->table(
            ['Status', 'Count'],
            collect(['queued', 'submitting', 'submitted', 'published', 'failed', 'cancelled'])
                ->map(fn ($s) => [$s, $byStatus[$s] ?? 0])
                ->all(),
        );

        // Recently-published — proves end-to-end works.
        $lastPublished = (clone $base)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        if ($lastPublished) {
            $this->info("Last published: post #{$lastPublished->id} at {$lastPublished->published_at} (platform_post_id={$lastPublished->platform_post_id})");
        } else {
            $this->warn('No published posts on record.');
        }

        // Published in window.
        $publishedInWindow = (clone $base)
            ->where('status', 'published')
            ->where('published_at', '>=', $since)
            ->count();
        $this->line("Published in last {$windowHours}h: {$publishedInWindow}");

        // Due-but-still-queued — these should clear within ~1 min thanks to
        // posts:dispatch-due. If they linger, something's wrong with cron.
        $stuckQueued = (clone $base)
            ->where('status', 'queued')
            ->where('scheduled_for', '<=', Carbon::now())
            ->orderBy('scheduled_for')
            ->get(['id', 'scheduled_for', 'brand_id']);

        if ($stuckQueued->isEmpty()) {
            $this->info('No due-but-queued backlog.');
        } else {
            $this->warn("DUE-BUT-QUEUED ({$stuckQueued->count()}) — cron may not be running or queue worker is down:");
            foreach ($stuckQueued->take(10) as $row) {
                $lateMin = (int) Carbon::now()->diffInMinutes($row->scheduled_for, false) * -1;
                $this->line("  #{$row->id} brand={$row->brand_id} due={$row->scheduled_for} ({$lateMin}m late)");
            }
            if ($stuckQueued->count() > 10) $this->line('  ... ' . ($stuckQueued->count() - 10) . ' more.');
        }

        // Stuck submitting (>5 min) — Blotato call hung or process crashed.
        $stuckSubmitting = (clone $base)
            ->where('status', 'submitting')
            ->where('updated_at', '<=', Carbon::now()->subMinutes(5))
            ->count();
        if ($stuckSubmitting > 0) {
            $this->warn("Stuck in 'submitting' >5min: {$stuckSubmitting} (likely crashed mid-call)");
        }

        // Recent failures.
        $recentFailures = (clone $base)
            ->where('status', 'failed')
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'last_error', 'attempt_count', 'updated_at']);
        if ($recentFailures->isNotEmpty()) {
            $this->line('');
            $this->warn("Recent failures (last {$windowHours}h, top 5):");
            foreach ($recentFailures as $f) {
                $err = (string) $f->last_error;
                if (mb_strlen($err) > 110) $err = mb_substr($err, 0, 107) . '...';
                $this->line("  #{$f->id} attempts={$f->attempt_count} {$f->updated_at}  {$err}");
            }
        }

        // Kill switch — globally and (if filtered) for that workspace.
        $this->line('');
        $this->line('== Kill-switch state ==');
        $paused = Workspace::query()
            ->when($workspaceId, fn ($q) => $q->where('id', $workspaceId))
            ->where('publishing_paused', true)
            ->get(['id', 'publishing_paused_reason']);
        if ($paused->isEmpty()) {
            $this->info('Publishing NOT paused on any workspace in scope.');
        } else {
            foreach ($paused as $w) {
                $this->warn("Workspace #{$w->id} PAUSED — {$w->publishing_paused_reason}");
            }
        }

        // Verdict line — the one-liner answer to "is it posting?"
        $this->line('');
        $verdict = $this->verdict($publishedInWindow, $stuckQueued->count(), $stuckSubmitting, $paused->count());
        $this->line('== Verdict ==');
        $this->line("  {$verdict}");
        $this->line('');

        return self::SUCCESS;
    }

    private function verdict(int $publishedInWindow, int $stuckQueued, int $stuckSubmitting, int $pausedCount): string
    {
        if ($pausedCount > 0) return 'PAUSED — kill switch is on, posts are intentionally not publishing.';
        if ($stuckQueued > 0) return "STUCK — {$stuckQueued} posts are due but still queued. Check the scheduler/queue worker.";
        if ($stuckSubmitting > 0) return "STUCK MID-FLIGHT — {$stuckSubmitting} posts have been 'submitting' for >5min. Likely needs operator intervention.";
        if ($publishedInWindow === 0) return 'NO RECENT ACTIVITY — nothing published in the window. Either nothing was scheduled, or the pipeline is silent.';
        return "HEALTHY — {$publishedInWindow} post(s) published in window, no backlog, no kill switch.";
    }
}
