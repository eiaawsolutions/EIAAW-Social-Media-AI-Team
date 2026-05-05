<?php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use App\Services\Blotato\PlatformRules;
use App\Services\Publishing\PostVerificationRules;
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

        // Idempotency guards. `submitted` rows that already have a
        // blotato_post_id are NOT terminal — they need polling to flip to
        // `published`. Without this branch the poller in PostsDispatchDue
        // re-dispatches them and they bounce off this guard forever, leaving
        // truly-published posts invisible in the live feed.
        if (in_array($post->status, ['published', 'cancelled'])) {
            return;
        }
        if ($post->status === 'submitted') {
            if ($post->blotato_post_id) {
                try {
                    $client = BlotatoClient::fromConfig();
                } catch (\Throwable $e) {
                    Log::warning('SubmitScheduledPost: poll-only client init failed', [
                        'id' => $post->id,
                        'error' => $e->getMessage(),
                    ]);
                    return;
                }
                $this->pollAndAdvance($post, $client);
            }
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

        // Defence-in-depth publishability gate. ComplianceAgent SHOULD have
        // caught this already, but pre-2026-05-05 drafts predate the
        // platform_publishability check, and the dispatcher does not re-read
        // draft.status before queuing. So we re-evaluate the same rules right
        // before the Blotato call. This stops the YouTube `TypeError: Failed
        // to parse URL from undefined` (failed/450758 class) and the IG/TikTok
        // text-only 422s from ever reaching Blotato.
        $eval = PlatformRules::evaluate($post->draft);
        if (! $eval['passed']) {
            $reasons = collect($eval['violations'])->pluck('reason')->implode(' | ');
            $this->markFailed($post, 'Publishability gate (pre-Blotato): ' . substr($reasons, 0, 250));
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

        // Per-platform caps that Blotato/native APIs enforce. Source: live
        // failures captured 2026-05-03 + Blotato 422 responses + native
        // platform documented limits as of Q2 2026. These differ from
        // WriterPrompt::PLATFORM_LIMITS which only covers body chars; here
        // we cap the COMPLETE caption (body + hashtags + mentions) because
        // that's what each platform actually counts toward its limit.
        $platformCaps = [
            'instagram' => ['caption' => 2200, 'hashtags' => 5],   // IG technically allows 30 but Blotato enforces 5
            'facebook' => ['caption' => 63206, 'hashtags' => 30],
            'linkedin' => ['caption' => 3000, 'hashtags' => 30],
            'tiktok' => ['caption' => 2200, 'hashtags' => 30],
            'threads' => ['caption' => 500, 'hashtags' => 10],
            'x' => ['caption' => 280, 'hashtags' => 5],
            'twitter' => ['caption' => 280, 'hashtags' => 5],
            'youtube' => ['caption' => 1000, 'hashtags' => 15],
            'pinterest' => ['caption' => 500, 'hashtags' => 20],
        ];
        $cap = $platformCaps[$post->draft->platform] ?? ['caption' => 1000, 'hashtags' => 10];

        // Build caption: trimmed body + capped hashtags + mentions, then
        // truncate the full assembled string if it still overflows the
        // platform cap. Hashtags lose first (least information density),
        // then body tail is …-truncated.
        $body = trim((string) $post->draft->body);
        $hashtags = array_slice(
            is_array($post->draft->hashtags) ? $post->draft->hashtags : [],
            0,
            $cap['hashtags'],
        );
        $mentions = is_array($post->draft->mentions) ? $post->draft->mentions : [];

        $caption = $body;
        if ($hashtags) {
            $caption .= "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags));
        }
        if ($mentions) {
            $caption .= "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions));
        }

        // Hard cap — truncate body if the full assembly is still over.
        if (mb_strlen($caption) > $cap['caption']) {
            // Compute how much room body has, leaving hashtag block intact
            // when possible. If even body alone is over cap, drop hashtags
            // entirely and trim body.
            $hashtagBlock = $hashtags
                ? "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags))
                : '';
            $mentionBlock = $mentions
                ? "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions))
                : '';
            $reserved = mb_strlen($hashtagBlock . $mentionBlock);
            $bodyRoom = $cap['caption'] - $reserved - 1; // -1 for the …
            if ($bodyRoom < 50) {
                // Hashtag block too greedy — drop it.
                $bodyRoom = $cap['caption'] - mb_strlen($mentionBlock) - 1;
                $caption = mb_substr($body, 0, max(50, $bodyRoom)) . '…' . $mentionBlock;
            } else {
                $caption = mb_substr($body, 0, $bodyRoom) . '…' . $hashtagBlock . $mentionBlock;
            }
        }

        // Map our internal platform enum to Blotato's expected string.
        // We store 'x' (the modern brand name); Blotato's content.platform
        // and target.targetType still use the legacy 'twitter'.
        $blotatoPlatform = $post->draft->platform === 'x' ? 'twitter' : $post->draft->platform;

        // Per-connection Blotato target overrides — empty/null for personal
        // accounts (LinkedIn personal, Threads personal, X personal — Blotato
        // routes to the profile by default), populated for business pages
        // (Facebook Page pageId, LinkedIn Company pageId, Pinterest boardId,
        // TikTok privacyLevel, YouTube privacyStatus, etc). Edit in
        // /agency/platforms → "Target overrides" row action.
        $targetOverrides = is_array($post->platformConnection->target_overrides)
            ? $post->platformConnection->target_overrides
            : [];

        try {
            $submissionId = $client->createPost(
                accountId: $post->platformConnection->blotato_account_id,
                platform: $blotatoPlatform,
                text: $caption,
                mediaUrls: $blotatoMediaUrls,
                scheduledTime: null, // we own scheduling — submit "now"
                targetOverrides: $targetOverrides,
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

        // Blotato status response shape (verified 2026-05-02 + 2026-05-06):
        //   { state|status: published|failed|processing, postId|post_id,
        //     postUrl|post_url, error|message, ... }
        // Blotato has been observed to nest the URL one level deep under
        // `result`, `data`, or `post` for older submissions, so fall through
        // to dotted lookups before declaring it missing.
        $state = strtolower((string) ($status['state'] ?? $status['status'] ?? ''));
        $platformPostId = $this->digKeys($status, ['postId', 'post_id', 'platformPostId', 'externalId', 'id']);
        $platformPostUrl = $this->digKeys($status, ['postUrl', 'post_url', 'platformPostUrl', 'permalink', 'url', 'shareUrl', 'share_url']);
        $error = $status['error'] ?? $status['message'] ?? null;

        if (in_array($state, ['published', 'success', 'completed'])) {
            // Verification gate — Blotato has been observed to return
            // state=published before its TikTok/YouTube/IG/Threads adapters
            // have actually delivered the post (see prod incident 2026-05-06:
            // 32 false-positive "published" rows; only 1 TikTok video and 0
            // YouTube videos actually existed). Require either platform_post_id
            // or a real-post-URL pattern before flipping to `published`.
            $platform = (string) ($post->draft?->platform ?? '');
            $verdict = PostVerificationRules::verify($platform, $platformPostId, $platformPostUrl);

            if (! $verdict['verified']) {
                // Stay in `submitted` — poller will revisit. Capture the
                // current Blotato payload so we can see what's missing.
                Log::info('SubmitScheduledPost: Blotato says published but verification failed; staying as submitted', [
                    'id' => $post->id,
                    'platform' => $platform,
                    'reason' => $verdict['reason'],
                    'blotato_post_id_returned' => $platformPostId,
                    'blotato_post_url_returned' => $platformPostUrl,
                    'blotato_status_keys' => array_keys($status),
                ]);
                // Refresh updated_at so the dispatcher's "poll if updated > 60s"
                // gate kicks back in; otherwise we'd thrash on every minute.
                $post->touch();
                return;
            }

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
            // Blotato sometimes returns a failed state with no error string,
            // producing the opaque "Platform rejected: " row in the failed list.
            // Fall back to the full status payload so the operator (and the
            // compliance learner) has SOMETHING to act on. Cap at 250 chars
            // total so DB column stays readable.
            $errorText = trim((string) $error);
            if ($errorText === '') {
                $errorText = 'no error string returned. Full status payload: '
                    . substr(json_encode($status, JSON_UNESCAPED_SLASHES) ?: '{}', 0, 200);
            }
            $this->markFailed(
                $post,
                'Platform rejected (' . $state . '): ' . substr($errorText, 0, 220),
                $status,
            );
            return;
        }

        // Still processing — leave as submitted; poller will revisit.
    }

    private function markFailed(ScheduledPost $post, string $reason, ?array $blotatoStatus = null): void
    {
        $post->update([
            'status' => 'failed',
            'last_error' => $reason,
        ]);
        Log::error('SubmitScheduledPost: failed', [
            'id' => $post->id,
            'platform' => $post->draft?->platform,
            'attempt' => $post->attempt_count,
            'reason' => $reason,
            'blotato_status' => $blotatoStatus,
        ]);

        // Feed the rejection into the compliance learner so future drafts on
        // the same platform avoid the same failure mode. Best-effort — never
        // block the publish path on telemetry.
        try {
            app(\App\Services\Compliance\LearnedRulesRecorder::class)
                ->recordRejection($post, $reason, $blotatoStatus);
        } catch (\Throwable $e) {
            Log::warning('SubmitScheduledPost: rejection telemetry failed (non-fatal)', [
                'id' => $post->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Search a (possibly nested) Blotato response for the first non-empty
     * value under any of the candidate keys. Walks one level into common
     * envelopes (`result`, `data`, `post`, `submission`) before giving up.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>    $keys
     */
    private function digKeys(array $payload, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (! empty($payload[$k]) && is_string($payload[$k])) {
                return $payload[$k];
            }
        }
        foreach (['result', 'data', 'post', 'submission'] as $envelope) {
            if (isset($payload[$envelope]) && is_array($payload[$envelope])) {
                foreach ($keys as $k) {
                    if (! empty($payload[$envelope][$k]) && is_string($payload[$envelope][$k])) {
                        return $payload[$envelope][$k];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Construct a best-guess profile URL for the brand's account on the
     * post's platform. Used when Blotato confirms `published` but doesn't
     * return the platform-side permalink — the operator gets a click that
     * lands them on the right account on the right network, which is much
     * better than a dead `#` anchor.
     */
    private function profileUrlFallback(ScheduledPost $post): ?string
    {
        $platform = $post->draft?->platform;
        $handle = $post->platformConnection?->display_handle;
        if (! $platform || ! $handle) return null;

        // Some handles are display names with spaces (e.g. "Amos Wafula").
        // Strip everything that isn't valid in a username and lowercase.
        $cleanHandle = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '', $handle) ?? '');
        if ($cleanHandle === '') return null;

        return match ($platform) {
            'instagram' => "https://www.instagram.com/{$cleanHandle}/",
            'tiktok'    => "https://www.tiktok.com/@{$cleanHandle}",
            'threads'   => "https://www.threads.com/@{$cleanHandle}",
            'youtube'   => "https://www.youtube.com/@{$cleanHandle}",
            'x', 'twitter' => "https://x.com/{$cleanHandle}",
            'facebook'  => "https://www.facebook.com/{$cleanHandle}",
            'linkedin'  => "https://www.linkedin.com/in/{$cleanHandle}",
            'pinterest' => "https://www.pinterest.com/{$cleanHandle}/",
            default     => null,
        };
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
