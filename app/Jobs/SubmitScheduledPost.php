<?php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Services\Billing\PlanCaps;
use App\Services\Blotato\PlatformRules;
use App\Services\Publishing\PublisherFactory;
use App\Services\Publishing\PublishResult;
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
        // set_time_limit(0): the catchable queue $timeout (120s) governs. Never
        // re-arm PHP's hard max_execution_time inside a queued job — it raises
        // an uncatchable fatal that kills the worker process.
        @set_time_limit(0);

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
                $this->pollViaPublisher($post);
            }
            return;
        }
        if ($post->status === 'failed' && $post->attempt_count >= 3) {
            return;
        }

        // PERMANENT failure — matched by ScheduledPost::isPermanentFailureReason()
        // ('Draft or platform connection missing'); cron does NOT auto-retry it.
        // Keep this message in sync with PERMANENT_FAILURE_SIGNATURES.
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

        // Monthly published-posts cap. When the workspace is at-cap, the
        // row flips to `queued_next_period` with `queued_for_period_at` =
        // first of next calendar month at 00:05 workspace TZ. The
        // posts:release-queued-next-period cron then flips it back to
        // 'queued' so the regular dispatcher picks it up. Customer keeps
        // their content (no failures) — they just see "queued for next
        // period" in the live feed.
        if ($brand && $brand->workspace
            && ! app(PlanCaps::class)->canPublishMorePosts($brand->workspace)) {
            $tz = (string) ($brand->workspace->settings['timezone'] ?? config('app.timezone', 'UTC'));
            $releaseAt = now($tz)->addMonthNoOverflow()->startOfMonth()->addMinutes(5)->utc();
            $post->update([
                'status' => 'queued_next_period',
                'queued_for_period_at' => $releaseAt,
                'last_error' => sprintf(
                    'Plan cap reached for this month. Auto-publishing on %s. Upgrade your plan at /agency/billing to publish now.',
                    $releaseAt->copy()->setTimezone($tz)->toDayDateTimeString(),
                ),
            ]);
            Log::info('SubmitScheduledPost: deferred — plan cap reached', [
                'id' => $post->id,
                'workspace_id' => $brand->workspace_id,
                'release_at' => $releaseAt->toIso8601String(),
            ]);
            return;
        }

        // PERMANENT failure — matched by ScheduledPost::isPermanentFailureReason()
        // ('connection is not active'); cron does NOT auto-retry a revoked/
        // expired/reauth_required connection (it never self-heals). Operator must
        // reconnect, or posts:repoint-revoked-connection repoints to an active
        // sibling. Keep this message in sync with PERMANENT_FAILURE_SIGNATURES.
        if ($post->platformConnection->status !== 'active') {
            $this->markFailed($post, 'Platform connection is not active (status=' . $post->platformConnection->status . ').');
            return;
        }

        $post->update([
            'status' => 'submitting',
            'attempt_count' => $post->attempt_count + 1,
        ]);

        // If we already have a provider submission id, this is a poll-only retry.
        if ($post->blotato_post_id) {
            $this->pollViaPublisher($post);
            return;
        }

        // Defence-in-depth publishability gate. ComplianceAgent SHOULD have
        // caught this already, but pre-2026-05-05 drafts predate the
        // platform_publishability check, and the dispatcher does not re-read
        // draft.status before queuing. So we re-evaluate the same rules right
        // before the provider call. This stops the YouTube `TypeError: Failed
        // to parse URL from undefined` (failed/450758 class) and the IG/TikTok
        // text-only 422s from ever reaching the provider.
        // PERMANENT failure — matched by ScheduledPost::isPermanentFailureReason()
        // ('Publishability gate (pre-publish)'); cron does NOT auto-retry it (the
        // same rules fail identically every tick). Operator fixes the draft then
        // Re-evaluates. Keep this prefix in sync with PERMANENT_FAILURE_SIGNATURES.
        $eval = PlatformRules::evaluate($post->draft, $post->platformConnection);
        if (! $eval['passed']) {
            $reasons = collect($eval['violations'])->pluck('reason')->implode(' | ');
            $this->markFailed($post, 'Publishability gate (pre-publish): ' . substr($reasons, 0, 250));
            return;
        }

        // Video-format integrity gate — added 2026-05-08 after SP25 (YouTube)
        // got auto-removed because we sent a stamped JPEG to /v2/posts on a
        // calendar entry asking for format=reel. Designer's regen action had
        // overwritten asset_url=mp4 with asset_url=jpeg, and YouTube's spam
        // classifier scrubbed the resulting 1-frame "video".
        //
        // Refusal is loud + actionable: the operator sees "Draft has stale
        // image asset on a video format. Re-run VideoAgent." in the failed
        // list. Auto-redraft will route to regenerate_media on the next tick.
        // PERMANENT failure — matched by ScheduledPost::isPermanentFailureReason()
        // ('Video-format draft has a still image'); cron does NOT auto-retry it
        // (it stays a still image until VideoAgent re-runs). Keep this message in
        // sync with PERMANENT_FAILURE_SIGNATURES.
        if ($this->draftNeedsVideoButHasImage($post->draft)) {
            $this->markFailed(
                $post,
                'Video-format draft has a still image as its primary asset (asset_url is .jpg/.png/etc). '
                . 'Calendar entry asks for format=reel|video|story on a video-capable platform but the publishable URL is not an mp4. '
                . 'Re-run VideoAgent on this draft (use the "Generate video" action in /agency/drafts).'
            );
            return;
        }

        // Source media URLs — the publisher re-hosts/normalises them as its
        // provider requires (Blotato /v2/media, Metricool /actions/normalize).
        $sourceMediaUrls = $this->collectDraftMediaUrls($post->draft);

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

        // HQ-only CTA links. SAME source as the compliance caption assembler
        // (PlatformRules::hqCtaBlock) so what ships equals what Compliance
        // counted. Empty for client brands / non-HQ / disabled config.
        $ctaBlock = '';
        $cta = \App\Services\Blotato\PlatformRules::hqCtaBlock($post->draft);
        if ($cta !== '') {
            $ctaBlock = "\n\n" . $cta;
        }

        $caption = $body;
        if ($hashtags) {
            $caption .= "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags));
        }
        if ($mentions) {
            $caption .= "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions));
        }
        $caption .= $ctaBlock;

        // Hard cap — truncate body if the full assembly is still over. The CTA
        // block is reserved (never truncated) so the links always survive; only
        // body (then hashtags) give way.
        if (mb_strlen($caption) > $cap['caption']) {
            // Compute how much room body has, leaving hashtag + CTA blocks intact
            // when possible. If even body alone is over cap, drop hashtags
            // entirely and trim body.
            $hashtagBlock = $hashtags
                ? "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags))
                : '';
            $mentionBlock = $mentions
                ? "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions))
                : '';
            $reserved = mb_strlen($hashtagBlock . $mentionBlock . $ctaBlock);
            $bodyRoom = $cap['caption'] - $reserved - 1; // -1 for the …
            if ($bodyRoom < 50) {
                // Hashtag block too greedy — drop it (keep mentions + CTA).
                $bodyRoom = $cap['caption'] - mb_strlen($mentionBlock . $ctaBlock) - 1;
                $caption = mb_substr($body, 0, max(50, $bodyRoom)) . '…' . $mentionBlock . $ctaBlock;
            } else {
                $caption = mb_substr($body, 0, $bodyRoom) . '…' . $hashtagBlock . $mentionBlock . $ctaBlock;
            }
        }

        // Hand off to the configured publisher (PUBLISH_PROVIDER: metricool
        // by default, blotato for rollback). The publisher owns media
        // re-hosting + the provider-specific submit; the result is normalised
        // to a provider-agnostic PublishResult.
        $publisher = app(PublisherFactory::class)->make();
        $result = $publisher->submit($post, $caption, $sourceMediaUrls);

        if ($result->state === 'failed') {
            $this->markFailed($post, ucfirst($publisher->key()) . ' submit failed: '
                . substr((string) $result->error, 0, 200), $result->raw);
            return;
        }

        if ($result->state === 'published') {
            // Some providers confirm synchronously — capture immediately.
            $this->applyPublished($post, $result);
            return;
        }

        // 'submitted' or 'pending' — store the provider submission id (column
        // name is historically blotato_post_id; it's the generic provider id)
        // and poll. A pending result with no id still flips to submitted so
        // the cron poller revisits via the publisher's poll().
        $post->update([
            'blotato_post_id' => $result->providerPostId ?: $post->blotato_post_id ?: 'pending',
            'status' => 'submitted',
            'submitted_at' => now(),
            'last_error' => null,
        ]);

        // Poll once now; subsequent polls happen via the cron poller.
        $this->pollViaPublisher($post);
    }

    /**
     * Poll an already-submitted post through the configured publisher and
     * advance its state. Provider-agnostic: the publisher returns a normalised
     * PublishResult (published / pending / failed), and we apply it.
     *
     * 'published' requires the publisher to have passed PostVerificationRules
     * (a real platform permalink/id) — neither Blotato nor Metricool's bare
     * "done" flag is trusted, preventing the false-positive-published incident
     * class (2026-05-06).
     */
    private function pollViaPublisher(ScheduledPost $post): void
    {
        if (! $post->blotato_post_id) {
            return;
        }

        try {
            $publisher = app(PublisherFactory::class)->make();
            $result = $publisher->poll($post);
        } catch (\Throwable $e) {
            Log::warning('SubmitScheduledPost: poll failed (will retry)', [
                'id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($result->state === 'published') {
            $this->applyPublished($post, $result);
            return;
        }

        if ($result->state === 'failed') {
            $this->markFailed($post, 'Platform rejected: ' . substr((string) $result->error, 0, 220), $result->raw);
            return;
        }

        // 'pending'/'submitted' — not verifiable yet. Touch so the dispatcher's
        // "poll if updated > 60s" gate re-arms instead of thrashing every tick.
        $post->touch();
    }

    /** Apply a verified-published PublishResult to the post + draft. */
    private function applyPublished(ScheduledPost $post, PublishResult $result): void
    {
        $post->update([
            'status' => 'published',
            'platform_post_id' => $result->platformPostId,
            'platform_post_url' => $result->platformPostUrl,
            'published_at' => now(),
            'last_error' => null,
        ]);
        // Bubble up to the draft so /agency/drafts shows it as published.
        $post->draft?->update(['status' => 'published']);

        // Close the publish→corpus loop: embed this now-live post into
        // brand_corpus so the Compliance dedup gate and the Writer's RAG learn
        // from the brand's OWN history (previously only manual seeds landed
        // there, so dedup was starved and the Strategist recycled topics).
        // Dispatched, never inline — an embedding outage must not undo a
        // verified publish.
        IngestPublishedPostToCorpus::dispatch($post->id);
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
     * True iff the draft's calendar entry asks for a video format on a
     * video-capable platform AND the draft.asset_url currently points to a
     * still image (jpeg/png/webp/gif). Used by the publish-time integrity
     * gate to refuse drafts where the image was regenerated but VideoAgent
     * never re-ran — sending a JPEG to YouTube/TikTok as a "video" gets
     * auto-removed by their spam classifiers (see SP25 incident 2026-05-07).
     */
    private function draftNeedsVideoButHasImage(\App\Models\Draft $draft): bool
    {
        $entry = $draft->calendarEntry;
        if (! $entry) return false;
        $format = strtolower((string) ($entry->format ?? ''));
        if (! in_array($format, ['reel', 'video', 'story'], true)) return false;
        if (! \App\Services\Imagery\FalAiClient::platformAcceptsVideo($draft->platform)) return false;

        $url = strtolower((string) ($draft->asset_url ?? ''));
        if ($url === '') return false; // missing-media gate handles this

        // Treat any explicit video extension as fine. URLs without an
        // extension (Blotato sometimes returns these for re-hosted media)
        // are accepted optimistically — the upstream PlatformRules media
        // check + Blotato's own validation are the next net.
        $videoExts = ['.mp4', '.mov', '.webm', '.m4v'];
        foreach ($videoExts as $ext) {
            if (str_ends_with($url, $ext)) return false;
        }
        $imageExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp', '.heic'];
        foreach ($imageExts as $ext) {
            if (str_ends_with($url, $ext)) return true;
        }

        // No recognised extension — let it through. Better than false-
        // failing on Blotato URLs that omit the extension.
        return false;
    }

    /**
     * The CURRENT publishable media for this draft: exactly the primary
     * asset_url, the single source of truth the rest of the publish path already
     * trusts (the video-format integrity gate above checks asset_url alone).
     *
     * We deliberately IGNORE asset_urls. Despite its "multi-asset (carousel)"
     * migration comment, no flow in this codebase produces a true multi-image
     * carousel — carousel/infographic formats render to ONE composed image. In
     * practice asset_urls is a history / provenance ledger: DesignerAgent appends
     * the final URL, VideoAgent appends the prior still + the new video, the
     * regenerate commands push the old asset_url before clearing it, and the
     * library path appends the source public_url. Sending those entries shipped
     * stale .mp4s, duplicate images, and dead ephemeral URLs as a bogus carousel
     * — and on 2026-06-20 it failed three HQ posts outright when a dead
     * /storage/branding/ URL in the history 404'd on-platform.
     *
     * Defense-in-depth: a /storage/branding/ URL is the ephemeral local public
     * disk (wiped on Railway redeploy, no storage:link → 404). If the primary is
     * itself ephemeral we return [] so the publish fails loudly via the
     * publishability gate (media_required) rather than 404-ing on-platform — the
     * draft then needs regeneration, not a publish.
     *
     * @return array<int,string>
     */
    private function collectDraftMediaUrls(\App\Models\Draft $draft): array
    {
        $primary = trim((string) ($draft->asset_url ?? ''));
        if ($primary === '' || $this->isEphemeralMediaUrl($primary)) {
            return [];
        }

        return [$primary];
    }

    /**
     * True for media URLs on the ephemeral local public disk
     * (/storage/branding/…), which 404 after a Railway redeploy and must never
     * be sent to a platform. Durable R2 URLs (smt-assets.eiaawsolutions.com)
     * pass. Matches the marker used by drafts:rehost-branding.
     */
    private function isEphemeralMediaUrl(string $url): bool
    {
        return str_contains($url, '/storage/branding/');
    }
}
