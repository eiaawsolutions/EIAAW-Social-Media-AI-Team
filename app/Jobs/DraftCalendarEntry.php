<?php

namespace App\Jobs;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Agents\RepurposeAgent;
use App\Agents\ResearcherAgent;
use App\Agents\VideoAgent;
use App\Agents\WriterAgent;
use App\Services\Imagery\FalAiClient;
use App\Models\CalendarEntry;
use App\Models\Draft;
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

        // Researcher runs once per entry (platform-agnostic). When N
        // platform jobs fan out for the same entry, the second/third see
        // research_brief already populated and skip via the cached path.
        // Soft-fail: missing research_brief lets Writer fall back to angle.
        if (empty($entry->research_brief)) {
            try {
                app(ResearcherAgent::class)->run($brand, [
                    'calendar_entry_id' => $entry->id,
                ]);
                $entry->refresh();
            } catch (\Throwable $e) {
                Log::warning('DraftCalendarEntry: Researcher crashed (continuing without brief)', [
                    'entry' => $entry->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        // Pillar fan-out: if the calendar entry is marked is_pillar AND this
        // job is running on the entry's PRIMARY platform (the first one in
        // entry.platforms), produce derivative drafts for the remaining
        // platforms via RepurposeAgent. Sibling DraftCalendarEntry jobs for
        // those other platforms detect the derivative via the $hasDraft gate
        // above and become no-ops.
        //
        // Why "first in entry.platforms" rather than e.g. always LinkedIn:
        // the operator chose the order; the longest-form / most-curated
        // platform is conventionally first. Forcing a hard-coded master
        // platform would break consumer brands where IG or TikTok is
        // the principal surface.
        $entryPlatforms = is_array($entry->platforms) ? array_values($entry->platforms) : [];
        $primaryPlatform = $entryPlatforms[0] ?? null;
        $isPillarMaster = (bool) ($entry->is_pillar ?? false)
            && $primaryPlatform !== null
            && strtolower((string) $this->platform) === strtolower((string) $primaryPlatform);

        if ($isPillarMaster) {
            $derivativeTargets = array_values(array_filter(
                $entryPlatforms,
                fn ($p) => is_string($p) && strtolower($p) !== strtolower($this->platform),
            ));

            if (! empty($derivativeTargets)) {
                try {
                    app(RepurposeAgent::class)->run($brand, [
                        'master_draft_id' => $draftId,
                        'target_platforms' => $derivativeTargets,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('DraftCalendarEntry: RepurposeAgent crashed (pillar fan-out abandoned)', [
                        'master_draft_id' => $draftId,
                        'error' => $e->getMessage(),
                    ]);
                }

                // After repurpose, kick Designer + Compliance for each new
                // derivative so the operator's table fills with media-ready,
                // gate-passed rows.
                $this->finishDerivatives($brand, $draftId);
            }
        }

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

    /**
     * Run Designer + (optionally) Video + Compliance for each derivative
     * draft of the given master. Soft-fails per derivative — one bad
     * derivative doesn't block the others.
     */
    private function finishDerivatives($brand, int $masterDraftId): void
    {
        $derivatives = Draft::where('parent_draft_id', $masterDraftId)
            ->where('status', 'compliance_pending')
            ->get();

        foreach ($derivatives as $derivative) {
            try {
                app(DesignerAgent::class)->run($brand, ['draft_id' => $derivative->id]);
            } catch (\Throwable $e) {
                Log::warning('DraftCalendarEntry: derivative Designer crashed', [
                    'draft_id' => $derivative->id, 'error' => $e->getMessage(),
                ]);
            }

            $entry = $derivative->calendarEntry;
            $needsVideo = $entry
                && in_array((string) ($entry->format ?? ''), ['reel', 'video', 'story'], true)
                && FalAiClient::platformAcceptsVideo($derivative->platform);

            if ($needsVideo) {
                try {
                    app(VideoAgent::class)->run($brand, ['draft_id' => $derivative->id]);
                } catch (\Throwable $e) {
                    Log::warning('DraftCalendarEntry: derivative VideoAgent crashed', [
                        'draft_id' => $derivative->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                app(ComplianceAgent::class)->run($brand, ['draft_id' => $derivative->id]);
            } catch (\Throwable $e) {
                Log::warning('DraftCalendarEntry: derivative Compliance crashed', [
                    'draft_id' => $derivative->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
