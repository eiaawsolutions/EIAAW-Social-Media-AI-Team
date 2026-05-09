<?php

namespace App\Jobs;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Agents\VideoAgent;
use App\Agents\WriterAgent;
use App\Models\Draft;
use App\Services\Imagery\FalAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-redraft loop for compliance-failed drafts.
 *
 * Closes the gap where a draft hits compliance_failed and just sits in the
 * Drafts table forever waiting for a human. The Writer is given the prior
 * body + every Compliance fail reason and asked to fix the violations while
 * preserving topic + angle. Compliance then re-runs.
 *
 * Capped via Draft.revision_count (default 3 attempts) so we don't burn LLM
 * budget on drafts the model can't fix — those stay compliance_failed and
 * surface to the operator. Idempotent: if the draft is no longer in
 * compliance_failed, this job exits silently.
 */
class RedraftFailedDraft implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    /** Hard cap on Writer rewrites per draft. After this, human attention required. */
    public const MAX_REVISIONS = 3;

    public function __construct(
        public int $draftId,
    ) {}

    public function handle(): void
    {
        @set_time_limit(180);

        $draft = Draft::find($this->draftId);
        if (! $draft) return;

        // Idempotency: only act on drafts that are still failed and under cap.
        // The cron may have queued multiple jobs for the same draft if the
        // operator also clicked 'Re-run Compliance' manually — second one
        // just exits.
        if ($draft->status !== 'compliance_failed') {
            return;
        }
        if (($draft->revision_count ?? 0) >= self::MAX_REVISIONS) {
            return;
        }
        if (! $draft->calendar_entry_id) {
            // No calendar entry to re-anchor against — Writer needs topic/angle
            // input. Surface this so the operator knows it's not silently
            // skipped forever.
            Log::info('RedraftFailedDraft: skipping — no calendar entry', ['draft_id' => $draft->id]);
            return;
        }

        $brand = $draft->brand;
        if (! $brand) return;

        // Snapshot the prior body + the fail reasons before Writer mutates the row.
        $priorBody = (string) $draft->body;
        $failures = $draft->complianceChecks()
            ->where('result', 'fail')
            ->orderBy('id')
            ->get(['check_type', 'reason', 'details'])
            ->map(fn ($c) => [
                'check_type' => $c->check_type,
                'reason' => $c->reason,
                'details' => $c->details,
            ])
            ->all();

        if (empty($failures)) {
            // Nothing to fix — defensive: status said failed but no fail rows.
            // Just re-run Compliance and let it correct the status.
            try {
                app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
            } catch (\Throwable $e) {
                Log::error('RedraftFailedDraft: defensive recompliance failed', [
                    'draft_id' => $draft->id, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // Route by failure kind. A missing-media failure can't be fixed by
        // Writer (no rewording adds an image) — that needs Designer/Video.
        // Redrafting the body for those is a wasted LLM call.
        $route = $this->routeFailures($failures);

        if ($route === 'regenerate_media') {
            // Missing-media failures: re-run Designer (and VideoAgent for
            // video formats). Skip Writer — body is fine, only the asset
            // needs producing. Then re-run Compliance against the now-
            // attached media.
            try {
                app(DesignerAgent::class)->run($brand, ['draft_id' => $draft->id]);
            } catch (\Throwable $e) {
                Log::warning('RedraftFailedDraft: Designer re-run failed', [
                    'draft_id' => $draft->id, 'error' => $e->getMessage(),
                ]);
            }

            // VideoAgent only if the calendar entry was a video format AND
            // the platform accepts video — same gate as DraftCalendarEntry.
            $entry = $draft->calendarEntry;
            $needsVideo = $entry
                && in_array((string) ($entry->format ?? ''), ['reel', 'video', 'story'], true)
                && FalAiClient::platformAcceptsVideo($draft->platform);
            if ($needsVideo) {
                try {
                    app(VideoAgent::class)->run($brand, ['draft_id' => $draft->id]);
                } catch (\Throwable $e) {
                    Log::warning('RedraftFailedDraft: Video re-run failed', [
                        'draft_id' => $draft->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $draft->forceFill([
                'revision_count' => ($draft->revision_count ?? 0) + 1,
                'last_redraft_at' => now(),
            ])->save();

            try {
                app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
            } catch (\Throwable $e) {
                Log::error('RedraftFailedDraft: post-media recompliance failed', [
                    'draft_id' => $draft->id, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        try {
            $writerResult = app(WriterAgent::class)->run($brand, [
                'calendar_entry_id' => $draft->calendar_entry_id,
                'platform' => $draft->platform,
                'redraft_context' => [
                    'draft_id' => $draft->id,
                    'prior_draft_id' => $draft->id,
                    'prior_body' => $priorBody,
                    'failures' => $failures,
                ],
            ]);

            if (! $writerResult->ok) {
                Log::warning('RedraftFailedDraft: Writer rewrite failed', [
                    'draft_id' => $draft->id,
                    'error' => $writerResult->errorMessage,
                ]);
                // Bump revision_count so we don't immediately retry the same
                // doomed draft on the next cron tick — let the cap exhaust.
                $draft->forceFill([
                    'revision_count' => ($draft->revision_count ?? 0) + 1,
                    'last_redraft_at' => now(),
                ])->save();
                return;
            }

            // Writer mutated the draft in-place (status reset to compliance_pending,
            // revision_count incremented). Now run Compliance against the new body.
            app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
        } catch (\Throwable $e) {
            Log::error('RedraftFailedDraft: redraft loop crashed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            // Bump the counter so a permanently-broken draft doesn't get
            // re-picked every cron tick. The error is logged for diagnosis.
            try {
                $draft->forceFill([
                    'revision_count' => ($draft->revision_count ?? 0) + 1,
                    'last_redraft_at' => now(),
                ])->save();
            } catch (\Throwable) {
                // swallow — we're already in an error path
            }
        }
    }

    /**
     * Decide how to recover from the failures on this draft. Two paths:
     *
     *   - 'regenerate_media' : the only platform_publishability failure is
     *                          missing media. Re-run Designer/Video, skip
     *                          Writer (body is fine).
     *   - 'rewrite'          : default — Writer rewrites the body to address
     *                          banned-phrase / dedup / brand-voice / factual
     *                          / caption-too-long / too-many-hashtags fails.
     *
     * If failures span both categories (e.g. media missing AND voice fail),
     * 'rewrite' wins so Writer fixes everything it can; the next Compliance
     * pass will re-flag the missing media and a subsequent redraft cycle
     * will route to 'regenerate_media'. This avoids running Writer +
     * Designer + Video on the same revision tick.
     *
     * @param  array<int,array{check_type:string,reason:string,details?:array}> $failures
     */
    private function routeFailures(array $failures): string
    {
        $kinds = $this->collectKinds($failures);

        // Media-only failures route to regenerate_media (Designer/Video).
        // Both kinds describe the same condition: the draft needs an asset
        // it doesn't have. media_required = platform-mandated (IG/TikTok/YT
        // text-only refusal). calendar_format_media_missing = calendar entry
        // says single_image/carousel/reel/video/story but asset_url is empty
        // even on text-permitting platforms (LinkedIn / Threads / Facebook).
        $mediaKinds = ['media_required', 'calendar_format_media_missing'];
        $rewriteableKinds = array_diff($kinds, $mediaKinds);
        $hasRewriteable = !empty($rewriteableKinds);

        if ($hasRewriteable) return 'rewrite';

        if (array_intersect($kinds, $mediaKinds)) return 'regenerate_media';

        return 'rewrite';
    }

    /**
     * Collect all failure kinds from the failures array. For
     * platform_publishability checks, the kinds live in details.kinds (added
     * by ComplianceAgent::checkPlatformPublishability). For other check types
     * the kind IS the check_type (banned_phrase, brand_voice, etc).
     *
     * @param  array<int,array{check_type:string,reason:string,details?:array}> $failures
     * @return array<int,string>
     */
    private function collectKinds(array $failures): array
    {
        $kinds = [];
        foreach ($failures as $f) {
            if (($f['check_type'] ?? '') === 'platform_publishability') {
                $detail = is_array($f['details'] ?? null) ? $f['details'] : [];
                foreach (($detail['kinds'] ?? []) as $k) {
                    $kinds[] = (string) $k;
                }
            } else {
                $kinds[] = (string) $f['check_type'];
            }
        }
        return array_values(array_unique($kinds));
    }
}
