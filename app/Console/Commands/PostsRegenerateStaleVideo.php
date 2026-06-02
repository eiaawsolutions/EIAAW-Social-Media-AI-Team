<?php

namespace App\Console\Commands;

use App\Agents\VideoAgent;
use App\Jobs\RegenerateStaleVideoPost;
use App\Models\ScheduledPost;
use App\Services\Imagery\FalAiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovery command for the "video-format draft has a still image as its
 * primary asset" publish failure (SubmitScheduledPost's video-format
 * integrity gate).
 *
 * THE GAP this closes:
 *   - A draft can reach status=scheduled with a JPEG on a reel/video format
 *     when VideoAgent crashed at draft time and the code "kept the still"
 *     (DraftCalendarEntry logs "VideoAgent crashed (kept still)") — e.g. during
 *     a FAL account-lockout window ([[fal-lockout-resilience]]).
 *   - At publish, SubmitScheduledPost's integrity gate refuses it (a 1-frame
 *     "video" gets auto-removed by platform spam classifiers) and marks the
 *     ScheduledPost failed.
 *   - The auto-redraft loop (RedraftFailedDraft) only fires on
 *     compliance_failed drafts — these are scheduled drafts that failed at
 *     PUBLISH time, so nothing ever re-runs VideoAgent. Permanent dead-end;
 *     the rows sit at attempt_count=3 (cap) and never auto-retry.
 *
 * WHAT IT DOES per matched ScheduledPost:
 *   1. Clear the draft's stale image (move it into asset_urls history) so the
 *      idempotent VideoAgent actually regenerates rather than no-op'ing on the
 *      existing asset.
 *   2. Re-run VideoAgent (force_fal — generate a real Veo 3 clip from the
 *      scripted scene brief). With the provider-aware rehostMedia fix, this now
 *      succeeds under Metricool (it used to hard-fail on the missing Blotato
 *      key — the reason re-running never worked before).
 *   3. If a video is now attached, requeue the post (status=queued,
 *      attempt_count=0) so posts:dispatch-due resubmits it. If VideoAgent could
 *      not produce a video (cap reached / FAL locked / text-only platform), the
 *      post is left failed with the real reason — never requeued blind.
 *
 * SAFETY:
 *   - Only touches posts whose last_error is the integrity-gate signature.
 *   - Skips any post that already holds a real provider id (no double-post).
 *   - Dry-run by default; --apply to write + actually call VideoAgent (costs
 *     FAL credit). --limit caps how many regenerate per run (video is slow +
 *     metered). --workspace scopes to one workspace.
 *
 * Usage:
 *   php artisan posts:regenerate-stale-video                 # dry-run
 *   php artisan posts:regenerate-stale-video --apply --limit=5
 *   php artisan posts:regenerate-stale-video --apply --workspace=2
 */
class PostsRegenerateStaleVideo extends Command
{
    /** The exact integrity-gate failure signature from SubmitScheduledPost. */
    private const SIGNATURE = 'still image as its primary asset';

    protected $signature = 'posts:regenerate-stale-video
                            {--workspace= : restrict to one workspace id (default: all)}
                            {--limit=0 : max posts to regenerate this run (0 = no limit; video is slow + metered)}
                            {--queue : dispatch each regen as a RegenerateStaleVideoPost job (worker, 330s budget) instead of running VideoAgent inline — REQUIRED when running over railway ssh, where a slow Veo i2v→t2v generation outlives the session and gets killed mid-run}
                            {--apply : actually clear assets, regenerate, and requeue (default is dry-run)}';

    protected $description = 'Recover posts that failed the video-format integrity gate: re-run VideoAgent on the stale-image draft, then requeue.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $queue = (bool) $this->option('queue');
        $limit = (int) $this->option('limit');
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $query = ScheduledPost::query()
            ->where('status', 'failed')
            ->where('last_error', 'like', '%' . self::SIGNATURE . '%')
            ->with(['draft.calendarEntry', 'brand.workspace']);

        if ($workspaceId !== null) {
            $query->whereHas('brand', fn ($q) => $q->where('workspace_id', $workspaceId));
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No posts match the video-format integrity-gate signature — nothing to recover.');
            return self::SUCCESS;
        }

        $stats = ['eligible' => 0, 'skipped_provider_id' => 0, 'skipped_no_draft' => 0,
                  'regenerated' => 0, 'requeued' => 0, 'regen_failed' => 0, 'dispatched' => 0];
        $processed = 0;

        foreach ($rows as $sp) {
            $providerId = (string) ($sp->blotato_post_id ?? '');
            if ($providerId !== '' && $providerId !== 'pending') {
                $this->warn(sprintf('SP%d: SKIP — holds provider id "%s" (poll, do not resubmit).', $sp->id, $providerId));
                $stats['skipped_provider_id']++;
                continue;
            }

            $draft = $sp->draft;
            $brand = $sp->brand;
            if (! $draft || ! $brand) {
                $this->warn("SP{$sp->id}: SKIP — missing draft or brand.");
                $stats['skipped_no_draft']++;
                continue;
            }

            $format = (string) ($draft->calendarEntry?->format ?? '?');
            $this->line(sprintf(
                'SP%d (%s/%s, ws#%s, draft#%d) stale asset: %s',
                $sp->id, $draft->platform, $format, $brand->workspace_id, $draft->id,
                substr((string) $draft->asset_url, 0, 70),
            ));

            $stats['eligible']++;

            if ($limit > 0 && $processed >= $limit) {
                $this->comment("  → SKIP (limit {$limit} reached this run).");
                continue;
            }

            if (! $apply) {
                $this->comment($queue
                    ? '  [dry-run] would DISPATCH RegenerateStaleVideoPost (worker regenerates + requeues).'
                    : '  [dry-run] would clear image, re-run VideoAgent (force_fal), then requeue if video attaches.');
                continue;
            }

            $processed++;

            // --queue: hand the slow regen to the worker (330s budget) instead
            // of running VideoAgent inline. The job does the identical clear →
            // VideoAgent → requeue/restore steps, but isn't tied to this (often
            // ssh) session, so a long Veo i2v→t2v generation can't be killed
            // mid-run. Use this over railway ssh; the inline path is for a local
            // shell or a worker context where the wall-clock is unbounded.
            if ($queue) {
                RegenerateStaleVideoPost::dispatch($sp->id)->onQueue('drafting');
                $stats['dispatched']++;
                $this->info('  → dispatched RegenerateStaleVideoPost (queue: drafting).');
                continue;
            }

            // 1. Clear the stale still so the idempotent VideoAgent regenerates.
            $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
            if ($draft->asset_url && ! in_array($draft->asset_url, $history, true)) {
                $history[] = $draft->asset_url;
            }
            $draft->update(['asset_url' => null, 'asset_urls' => array_values($history)]);

            // 2. Re-run VideoAgent (force the FAL scripted-brief path).
            try {
                $result = app(VideoAgent::class)->run($brand, [
                    'draft_id' => $draft->id,
                    'force_fal' => true,
                ]);
            } catch (\Throwable $e) {
                $this->error('  → VideoAgent threw: ' . substr($e->getMessage(), 0, 160));
                $stats['regen_failed']++;
                continue;
            }

            $fresh = $draft->fresh();
            $nowVideo = $this->urlIsVideo((string) $fresh?->asset_url);

            if (! $result->ok || ! $nowVideo) {
                $this->error('  → no video produced (' . ($result->ok ? 'asset not a video' : $result->errorMessage) . '). Left failed.');
                $stats['regen_failed']++;
                continue;
            }

            $stats['regenerated']++;
            $this->info('  → video attached: ' . substr((string) $fresh->asset_url, 0, 70));

            // 3. Requeue the post so the dispatcher resubmits it.
            DB::transaction(function () use ($sp, &$stats) {
                $sp->update(['status' => 'queued', 'attempt_count' => 0, 'last_error' => null]);
                $stats['requeued']++;
            });
            $this->info('  → SP' . $sp->id . ' requeued.');
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line('matched failed rows:        ' . $rows->count());
        $this->line('eligible:                   ' . $stats['eligible']);
        $this->line('skipped (has provider id):  ' . $stats['skipped_provider_id']);
        $this->line('skipped (no draft/brand):   ' . $stats['skipped_no_draft']);
        if ($queue) {
            $this->line('dispatched to worker:       ' . $stats['dispatched']);
        } else {
            $this->line('video regenerated:          ' . $stats['regenerated']);
            $this->line('requeued:                   ' . $stats['requeued']);
            $this->line('regen failed (left failed): ' . $stats['regen_failed']);
        }
        $this->line('');

        if (! $apply) {
            $this->warn($queue
                ? 'DRY-RUN — no jobs dispatched. Re-run with --apply --queue to dispatch (worker regenerates; costs FAL credit).'
                : 'DRY-RUN — nothing written, no VideoAgent calls. Re-run with --apply to recover (costs FAL credit).');
        } elseif ($queue) {
            $this->info('Jobs dispatched to the `drafting` queue. The worker regenerates each video (i2v→t2v self-heal) and requeues the post on success; posts:dispatch-due then resubmits. Watch worker logs / re-run this command to see remaining still-only rows.');
        } else {
            $this->info('Requeued rows will be picked up by posts:dispatch-due on the next cron tick (≤1 min).');
        }

        return self::SUCCESS;
    }

    /** True if the URL ends in a recognised video extension. */
    private function urlIsVideo(string $url): bool
    {
        $url = strtolower($url);
        foreach (['.mp4', '.mov', '.webm', '.m4v'] as $ext) {
            if (str_ends_with($url, $ext)) {
                return true;
            }
        }
        // FAL/storage video URLs without an extension are rare; if VideoAgent
        // returned ok and the URL is not a known image, accept it as video.
        foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.heic'] as $ext) {
            if (str_ends_with($url, $ext)) {
                return false;
            }
        }
        return $url !== '';
    }
}
