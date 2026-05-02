<?php

namespace App\Jobs;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Agents\VideoAgent;
use App\Agents\WriterAgent;
use App\Services\Imagery\FalAiClient;
use App\Models\CalendarEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drafts ONE (calendar entry, platform) pair end-to-end:
 *   Writer -> Designer -> Compliance.
 *
 * Used by the /agency/calendar 'Draft all' bulk action which fans out one
 * job per entry per platform. Each job is independent; failures of one
 * don't block the rest. Already-drafted (entry, platform) pairs are no-op.
 */
class DraftCalendarEntry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public int $calendarEntryId,
        public string $platform,
    ) {}

    public function handle(): void
    {
        @set_time_limit(300);

        $entry = CalendarEntry::find($this->calendarEntryId);
        if (! $entry) return;

        // Idempotent: skip if a draft already exists for this (entry, platform).
        $hasDraft = $entry->drafts()
            ->where('platform', $this->platform)
            ->whereNotIn('status', ['rejected'])
            ->exists();
        if ($hasDraft) return;

        $brand = $entry->brand;
        if (! $brand) return;

        try {
            $writer = app(WriterAgent::class)->run($brand, [
                'calendar_entry_id' => $entry->id,
                'platform' => $this->platform,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DraftCalendarEntry: Writer crashed', [
                'entry' => $entry->id,
                'platform' => $this->platform,
                'error' => $e->getMessage(),
            ]);
            return;
        }
        if (! $writer->ok) {
            Log::warning('DraftCalendarEntry: Writer fail', [
                'entry' => $entry->id,
                'platform' => $this->platform,
                'error' => $writer->errorMessage,
            ]);
            return;
        }

        $draftId = $writer->data['draft_id'] ?? null;
        if (! $draftId) return;

        // Designer always runs — even for video formats, the still becomes
        // the keyframe for image-to-video (better brand consistency).
        // Soft-fail: text-only drafts still pass through.
        try {
            app(DesignerAgent::class)->run($brand, ['draft_id' => $draftId]);
        } catch (\Throwable $e) {
            Log::warning('DraftCalendarEntry: Designer crashed (kept text-only)', [
                'draft_id' => $draftId,
                'error' => $e->getMessage(),
            ]);
        }

        // Video gate: format requires video AND platform accepts video.
        // Skips text-only platforms and non-video formats. Video cap is
        // separate from image cap so 30 stills fit in $1.20 / day budget
        // even with a few videos mixed in.
        $needsVideo = in_array((string) ($entry->format ?? ''), ['reel', 'video', 'story'], true)
            && FalAiClient::platformAcceptsVideo($this->platform);
        if ($needsVideo) {
            try {
                app(VideoAgent::class)->run($brand, ['draft_id' => $draftId]);
            } catch (\Throwable $e) {
                Log::warning('DraftCalendarEntry: VideoAgent crashed (kept still)', [
                    'draft_id' => $draftId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(ComplianceAgent::class)->run($brand, ['draft_id' => $draftId]);
        } catch (\Throwable $e) {
            Log::warning('DraftCalendarEntry: Compliance crashed', [
                'draft_id' => $draftId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
