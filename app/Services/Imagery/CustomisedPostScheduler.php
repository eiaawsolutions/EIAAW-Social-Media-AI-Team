<?php

namespace App\Services\Imagery;

use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Draft;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns one customised-intent BrandAsset + a narrative + target platforms +
 * a publish datetime into scheduled posts — WITHOUT inventing a parallel
 * publishing rail.
 *
 * It produces the exact same artefacts the autonomous pipeline produces:
 *   - a "Customised posts" ContentCalendar (one per brand, reused)
 *   - one CalendarEntry per submission, carrying the chosen date/time (so the
 *     existing posts:auto-schedule-approved resolves scheduled_for in the
 *     brand's timezone from scheduled_date + scheduled_time, unchanged)
 *   - one Draft per target platform, with:
 *       * body = the operator's (or AI-written, already-reviewed) narrative
 *       * asset_url pre-attached from the BrandAsset (Blotato re-host happens
 *         here, mirroring DesignerAgent's library-pick path)
 *       * status = 'approved' so the cron rail schedules + publishes it
 *
 * The downstream chain is untouched:
 *   posts:auto-schedule-approved → ScheduledPost(queued)
 *   posts:dispatch-due           → SubmitScheduledPost → Blotato
 *
 * Why status='approved' and not the compliance gate: the operator authored
 * (or explicitly reviewed) this copy and hand-picked the asset. This is a
 * deliberate, human-in-the-loop publish — not an autonomous draft. The
 * publish-time PlatformRules gate in SubmitScheduledPost is still the safety
 * net for caption/media validity.
 */
class CustomisedPostScheduler
{
    /** Platforms the customised flow can target (subset of WriterPrompt limits). */
    public const SUPPORTED_PLATFORMS = [
        'instagram', 'facebook', 'linkedin', 'tiktok',
        'threads', 'x', 'youtube', 'pinterest',
    ];

    public function __construct(
        private readonly BlotatoRehost $rehost,
    ) {}

    /**
     * @param  array<int,string>  $platforms  target platform enums
     * @return array{calendar_entry: CalendarEntry, drafts: array<int,Draft>, asset: BrandAsset}
     */
    public function schedule(
        BrandAsset $asset,
        Brand $brand,
        string $narrative,
        array $platforms,
        Carbon $publishAt,
        string $narrativeSource = 'manual',
        ?array $hashtags = null,
    ): array {
        $platforms = self::normalisePlatforms($platforms);
        if (empty($platforms)) {
            throw new \InvalidArgumentException('Pick at least one supported platform.');
        }
        $narrative = trim($narrative);
        if ($narrative === '') {
            throw new \InvalidArgumentException('A post narrative is required.');
        }
        if ($asset->brand_id !== $brand->id) {
            // Tenant-safety: never let an asset from another brand be scheduled here.
            throw new \InvalidArgumentException('Asset does not belong to this brand.');
        }

        // Re-host the asset on THIS workspace's Blotato account once, up-front,
        // so every per-platform draft shares the same Blotato-scoped media URL.
        // Soft-fail to the raw public_url — SubmitScheduledPost re-uploads media
        // at publish time anyway, so a failure here is not fatal.
        $assetUrl = $this->rehost->forBrand($brand, $asset->public_url) ?? $asset->public_url;

        return DB::transaction(function () use (
            $asset, $brand, $narrative, $platforms, $publishAt, $narrativeSource, $hashtags, $assetUrl
        ): array {
            $calendar = $this->customisedCalendar($brand);
            $entry = $this->createEntry($calendar, $brand, $asset, $platforms, $publishAt);

            $drafts = [];
            foreach ($platforms as $platform) {
                $drafts[] = $this->createDraft($brand, $entry, $asset, $assetUrl, $platform, $narrative, $hashtags);
            }

            // Stamp the asset with its customised-post provenance + reserve it
            // out of the general picker pool.
            $asset->forceFill([
                'usage_intent' => BrandAsset::INTENT_CUSTOMISED,
                'scheduled_platforms' => $platforms,
                'scheduled_post_for' => $publishAt,
                'narrative_source' => $narrativeSource,
                'customised_calendar_entry_id' => $entry->id,
            ])->save();

            app(SetupReadiness::class)->invalidate($brand);

            return ['calendar_entry' => $entry, 'drafts' => $drafts, 'asset' => $asset];
        });
    }

    /** One reusable "Customised posts" calendar per brand. */
    private function customisedCalendar(Brand $brand): ContentCalendar
    {
        return ContentCalendar::firstOrCreate(
            ['brand_id' => $brand->id, 'label' => 'Customised posts'],
            [
                'period_starts_on' => now()->startOfMonth()->toDateString(),
                'period_ends_on' => now()->addYear()->endOfMonth()->toDateString(),
                'status' => 'approved',
            ],
        );
    }

    private function createEntry(
        ContentCalendar $calendar,
        Brand $brand,
        BrandAsset $asset,
        array $platforms,
        Carbon $publishAt,
    ): CalendarEntry {
        // The asset's vision description anchors topic + visual_direction so any
        // later agent touchpoint (e.g. re-running Designer) stays on-subject.
        $topic = trim((string) ($asset->description ?: ($asset->original_filename ?: 'Customised post')));
        $visualDirection = $asset->description
            ? 'Use the operator-uploaded asset depicting: ' . $asset->description
            : 'Operator-uploaded brand asset.';

        // Store date in brand TZ; the auto-scheduler combines scheduled_date +
        // scheduled_time in the brand TZ and converts to UTC. We persist the
        // user's chosen instant interpreted in the brand timezone.
        $brandTz = $brand->timezone ?: 'UTC';
        $local = $publishAt->copy()->setTimezone($brandTz);

        return CalendarEntry::create([
            'content_calendar_id' => $calendar->id,
            'brand_id' => $brand->id,
            'scheduled_date' => $local->toDateString(),
            'scheduled_time' => $local->format('H:i:s'),
            'topic' => mb_substr($topic, 0, 255),
            'angle' => 'Operator-customised post',
            'pillar' => 'custom',
            'format' => $asset->isVideo() ? 'video' : 'single_image',
            'platforms' => $platforms,
            'objective' => 'operator_scheduled',
            'visual_direction' => mb_substr($visualDirection, 0, 1000),
            'is_pillar' => false,
            'status' => 'approved',
        ]);
    }

    private function createDraft(
        Brand $brand,
        CalendarEntry $entry,
        BrandAsset $asset,
        string $assetUrl,
        string $platform,
        string $narrative,
        ?array $hashtags,
    ): Draft {
        // Respect the platform body cap (same source of truth the Writer uses).
        $cap = \App\Agents\Prompts\WriterPrompt::PLATFORM_LIMITS[$platform] ?? 1000;
        $body = mb_substr($narrative, 0, $cap);

        return Draft::create([
            'brand_id' => $brand->id,
            'calendar_entry_id' => $entry->id,
            'platform' => $platform,
            'content_type' => $asset->isVideo() ? 'video' : 'caption',
            'body' => $body,
            'hashtags' => is_array($hashtags) ? array_slice($hashtags, 0, 30) : [],
            'mentions' => [],
            // Asset pre-attached — bypasses DesignerAgent entirely.
            'asset_url' => $assetUrl,
            'asset_urls' => array_values(array_unique([$assetUrl, $asset->public_url])),
            // Provenance — this is an operator action, not an agent generation.
            'agent_role' => 'operator',
            'prompt_version' => 'customised-post.v1',
            'prompt_inputs' => [
                'brand_asset_id' => $asset->id,
                'narrative_source' => $asset->narrative_source ?? 'manual',
            ],
            'cost_usd' => 0,
            // Human-approved publish — skip the compliance/redraft gate and
            // land straight in the auto-scheduler's pickup state.
            'status' => 'approved',
            'lane' => $brand->defaultLaneFor($platform),
            'approved_by_user_id' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Lowercase, de-dupe, and drop anything not in SUPPORTED_PLATFORMS.
     * Public + static so it's the single source of truth for "which platforms
     * are valid for a customised post" and is unit-testable without the DB.
     *
     * @param  array<int,string>  $platforms
     * @return array<int,string>
     */
    public static function normalisePlatforms(array $platforms): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($p) => strtolower(trim((string) $p)), $platforms),
            fn ($p) => in_array($p, self::SUPPORTED_PLATFORMS, true),
        )));
    }
}
