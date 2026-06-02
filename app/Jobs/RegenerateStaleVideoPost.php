<?php

namespace App\Jobs;

use App\Agents\VideoAgent;
use App\Models\ScheduledPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued counterpart to the per-draft step of `posts:regenerate-stale-video`.
 *
 * Why a job: a single Veo native-audio generation — especially an
 * image-to-video that then RETRIES as text-to-video on a content-policy refusal
 * (VideoAgent's i2v→t2v self-heal) — outlives an interactive `railway ssh`
 * session (~250-290s), so running the regen synchronously over ssh gets the
 * process KILLED mid-generation and nothing persists. The worker
 * (queue:work --timeout=330) is the right runtime: it owns a proper wall-clock
 * budget and isn't tied to any operator session. The command's --queue flag
 * dispatches this instead of running VideoAgent inline.
 *
 * Steps (identical to the command's inline path, so behaviour matches exactly):
 *   1. Clear the draft's stale still (preserved in asset_urls history) so the
 *      idempotent VideoAgent regenerates rather than no-op'ing on the asset.
 *   2. Re-run VideoAgent (force_fal — scripted-brief Veo path; the i2v→t2v
 *      self-heal handles a flagged keyframe).
 *   3. If a real video is now attached, requeue the post (status=queued,
 *      attempt_count=0) so posts:dispatch-due resubmits it. If no video was
 *      produced (content-policy on both i2v AND t2v, cap, lockout), restore the
 *      still and leave the post failed with the real reason — never requeue a
 *      still on a video format (it would just re-fail the integrity gate).
 *
 * Idempotent + safe: re-running on a post that already holds a real provider id
 * is a no-op (no double-post); a row that's no longer failed is skipped.
 */
class RegenerateStaleVideoPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * Wall-clock budget. VideoAgent may run i2v + a t2v retry + extend steps, so
     * 300s gives real headroom while staying under the worker's --timeout=330
     * process cap (re-arming PHP's own limit inside a job fatals the worker —
     * see [[worker-timeout-contract]]).
     */
    public int $timeout = 300;

    public function __construct(public int $scheduledPostId) {}

    public function handle(): void
    {
        // Let the catchable queue $timeout govern — never set_time_limit(N>0).
        @set_time_limit(0);

        $sp = ScheduledPost::with(['draft', 'brand'])->find($this->scheduledPostId);
        if (! $sp) {
            return;
        }

        // Only act on a still-failed row that hasn't already submitted.
        if ($sp->status !== 'failed') {
            return;
        }
        $providerId = (string) ($sp->blotato_post_id ?? '');
        if ($providerId !== '' && $providerId !== 'pending') {
            return; // already submitted — poll, don't resubmit
        }

        $draft = $sp->draft;
        $brand = $sp->brand;
        if (! $draft || ! $brand) {
            return;
        }

        // 1. Snapshot + clear the stale still so VideoAgent regenerates.
        $priorStill = (string) ($draft->asset_url ?? '');
        $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
        if ($priorStill !== '' && ! in_array($priorStill, $history, true)) {
            $history[] = $priorStill;
        }
        $draft->update(['asset_url' => null, 'asset_urls' => array_values($history)]);

        // 2. Re-run VideoAgent (force the FAL scripted-brief path).
        try {
            $result = app(VideoAgent::class)->run($brand, [
                'draft_id' => $draft->id,
                'force_fal' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RegenerateStaleVideoPost: VideoAgent threw', [
                'post_id' => $sp->id,
                'draft_id' => $draft->id,
                'error' => substr($e->getMessage(), 0, 200),
            ]);
            $this->restoreStill($draft, $priorStill);
            return;
        }

        $fresh = $draft->fresh();
        if (! $result->ok || ! self::urlIsVideo((string) $fresh?->asset_url)) {
            Log::info('RegenerateStaleVideoPost: no video produced; leaving post failed', [
                'post_id' => $sp->id,
                'draft_id' => $draft->id,
                'reason' => $result->ok ? 'asset not a video' : substr((string) $result->errorMessage, 0, 200),
            ]);
            $this->restoreStill($fresh ?? $draft, $priorStill);
            return;
        }

        // 3. Video attached — requeue so the dispatcher resubmits it.
        DB::transaction(function () use ($sp) {
            $sp->update(['status' => 'queued', 'attempt_count' => 0, 'last_error' => null]);
        });

        Log::info('RegenerateStaleVideoPost: video attached + post requeued', [
            'post_id' => $sp->id,
            'draft_id' => $draft->id,
            'asset_url' => substr((string) $fresh->asset_url, 0, 80),
        ]);
    }

    /**
     * Restore a still if the regen produced no video — so the draft is never
     * left media-less (a still on a video format still fails the integrity gate,
     * but it preserves the asset for the operator / a later scene-brief edit).
     *
     * Prefer the still this job cleared ($priorStill); if that's empty (a prior
     * run already cleared asset_url before this job ran), fall back to the most
     * recent still in asset_urls history. Without the history fallback a
     * repeatedly-regenerated stubborn draft ends up media-less.
     */
    private function restoreStill(\App\Models\Draft $draft, string $priorStill): void
    {
        if (! empty($draft->asset_url)) {
            return; // already has media
        }

        $still = $priorStill;
        if ($still === '') {
            $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
            // Most recent non-video entry in history.
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $candidate = (string) $history[$i];
                if ($candidate !== '' && ! self::urlIsVideo($candidate)) {
                    $still = $candidate;
                    break;
                }
            }
        }

        if ($still !== '') {
            $draft->update(['asset_url' => $still]);
        }
    }

    /** True if the URL ends in a recognised video extension. Mirrors the command. */
    public static function urlIsVideo(string $url): bool
    {
        $url = strtolower($url);
        foreach (['.mp4', '.mov', '.webm', '.m4v'] as $ext) {
            if (str_ends_with($url, $ext)) {
                return true;
            }
        }
        foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.heic'] as $ext) {
            if (str_ends_with($url, $ext)) {
                return false;
            }
        }
        return $url !== '';
    }
}
