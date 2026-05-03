<?php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Submits one ScheduledPost row to Blotato. State machine:
 *
 *   queued -> submitting (we're calling /v2/posts now)
 *           -> submitted   (Blotato accepted, post is processing on platform)
 *           -> published   (platform_post_id captured; live on the network)
 *           -> failed      (Blotato or platform rejected; last_error populated)
 *
 * Idempotency: re-running on a non-queued/failed row is a no-op. Re-running
 * on a `failed` row that already has a blotato_post_id polls status instead
 * of re-creating, so we never publish twice.
 *
 * Retry: queued + failed rows can be retried up to 3 times via the cron
 * dispatcher. Each call to handle() bumps attempt_count.
 */
class SubmitScheduledPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // we manage our own retry semantics via attempt_count
    public int $timeout = 120;

    public function __construct(public int $scheduledPostId) {}

    public function handle(): void
    {
        @set_time_limit(120);

        $post = ScheduledPost::with(['draft', 'platformConnection', 'brand'])->find($this->scheduledPostId);
        if (! $post) {
            Log::warning('SubmitScheduledPost: row vanished', ['id' => $this->scheduledPostId]);
            return;
        }

        // Idempotency guards
        if (in_array($post->status, ['submitted', 'published', 'cancelled'])) {
            return;
        }
        if ($post->status === 'failed' && $post->attempt_count >= 3) {
            return;
        }

        if (! $post->draft || ! $post->platformConnection) {
            $this->markFailed($post, 'Draft or platform connection missing.');
            return;
        }

        // Per-workspace kill switch — refuse to publish without marking
        // the row failed (so it stays queued for resume).
        $brand = $post->brand;
        if ($brand && $brand->workspace && $brand->workspace->publishing_paused) {
            Log::info('SubmitScheduledPost: publishing paused for workspace', [
                'id' => $post->id,
                'workspace_id' => $brand->workspace_id,
                'reason' => $brand->workspace->publishing_paused_reason,
            ]);
            return; // stays in 'queued', will be picked up after resume
        }

        if ($post->platformConnection->status !== 'active') {
            $this->markFailed($post, 'Platform connection is not active (status=' . $post->platformConnection->status . ').');
            return;
        }

        $post->update([
            'status' => 'submitting',
            'attempt_count' => $post->attempt_count + 1,
        ]);

        try {
            $client = BlotatoClient::fromConfig();
        } catch (\Throwable $e) {
            $this->markFailed($post, 'Blotato config error: ' . $e->getMessage());
            return;
        }

        // If we already have a blotato_post_id, this is a poll-only retry.
        if ($post->blotato_post_id) {
            $this->pollAndAdvance($post, $client);
            return;
        }

        // Upload media first (Blotato requires its own URLs in createPost).
        $blotatoMediaUrls = [];
        $sourceMediaUrls = $this->collectDraftMediaUrls($post->draft);
        foreach ($sourceMediaUrls as $url) {
            try {
                $blotatoMediaUrls[] = $client->uploadMediaFromUrl($url);
            } catch (\Throwable $e) {
                $this->markFailed($post, 'Media upload failed: ' . substr($e->getMessage(), 0, 200));
                return;
            }
        }

        // Build caption (body + hashtags + mentions). Blotato treats text as
        // free-form, so we serialize hashtags/mentions inline at the end —
        // platform-specific formatting (LinkedIn vs X) lives in WriterAgent.
        $caption = trim((string) $post->draft->body);
        $hashtags = is_array($post->draft->hashtags) ? $post->draft->hashtags : [];
        $mentions = is_array($post->draft->mentions) ? $post->draft->mentions : [];
        if ($hashtags) {
            $caption .= "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags));
        }
        if ($mentions) {
            $caption .= "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions));
        }

        // Map our internal platform enum to Blotato's expected string.
        // We store 'x' (the modern brand name); Blotato's content.platform
        // and target.targetType still use the legacy 'twitter'.
        $blotatoPlatform = $post->draft->platform === 'x' ? 'twitter' : $post->draft->platform;

        try {
            $submissionId = $client->createPost(
                accountId: $post->platformConnection->blotato_account_id,
                platform: $blotatoPlatform,
                text: $caption,
                mediaUrls: $blotatoMediaUrls,
                scheduledTime: null, // we own scheduling — submit "now"
            );
        } catch (\Throwable $e) {
            $this->markFailed($post, 'Blotato createPost failed: ' . substr($e->getMessage(), 0, 200));
            return;
        }

        $post->update([
            'blotato_post_id' => $submissionId,
            'status' => 'submitted',
            'submitted_at' => now(),
            'last_error' => null,
        ]);

        // Poll once now; subsequent polls happen via the cron poller.
        $this->pollAndAdvance($post, $client);
    }

    private function pollAndAdvance(ScheduledPost $post, BlotatoClient $client): void
    {
        if (! $post->blotato_post_id) return;

        try {
            $status = $client->getPostStatus($post->blotato_post_id);
        } catch (\Throwable $e) {
            // Keep status='submitted' — we'll retry the poll later.
            Log::warning('SubmitScheduledPost: poll failed (will retry)', [
                'id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // Blotato status response shape: { state: published|failed|processing, postId, postUrl, error }
        $state = strtolower((string) ($status['state'] ?? $status['status'] ?? ''));
        $platformPostId = $status['postId'] ?? $status['post_id'] ?? null;
        $platformPostUrl = $status['postUrl'] ?? $status['post_url'] ?? null;
        $error = $status['error'] ?? $status['message'] ?? null;

        if (in_array($state, ['published', 'success', 'completed'])) {
            $post->update([
                'status' => 'published',
                'platform_post_id' => $platformPostId,
                'platform_post_url' => $platformPostUrl,
                'published_at' => now(),
                'last_error' => null,
            ]);
            // Bubble up to the draft so /agency/drafts shows it as published.
            $post->draft?->update(['status' => 'published']);
            return;
        }

        if (in_array($state, ['failed', 'error', 'rejected'])) {
            $this->markFailed($post, 'Platform rejected: ' . substr((string) $error, 0, 200));
            return;
        }

        // Still processing — leave as submitted; poller will revisit.
    }

    private function markFailed(ScheduledPost $post, string $reason): void
    {
        $post->update([
            'status' => 'failed',
            'last_error' => $reason,
        ]);
        Log::error('SubmitScheduledPost: failed', [
            'id' => $post->id,
            'attempt' => $post->attempt_count,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function collectDraftMediaUrls(\App\Models\Draft $draft): array
    {
        $urls = [];
        if ($draft->asset_url) {
            $urls[] = $draft->asset_url;
        }
        if (is_array($draft->asset_urls)) {
            foreach ($draft->asset_urls as $u) {
                if (is_string($u) && $u !== '') $urls[] = $u;
            }
        }
        return array_values(array_unique($urls));
    }
}
