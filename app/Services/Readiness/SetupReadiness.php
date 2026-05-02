<?php

namespace App\Services\Readiness;

use App\Models\Brand;
use App\Models\BrandStyle;
use App\Models\BrandCorpusItem;
use App\Models\PlatformConnection;
use App\Models\AutonomySetting;
use App\Models\ContentCalendar;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Models\PerformanceUpload;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * The brain of the Setup Wizard. Runs verifiable DB queries to detect
 * what's configured and what's missing for any brand. Never fabricates;
 * every "done" claim is backed by a row count or a NOT NULL check.
 *
 * Cache: 30s per brand to prevent burning queries on every Filament re-render,
 * but short enough that a user who just connected a platform sees the change
 * within half a minute.
 */
class SetupReadiness
{
    private const CACHE_TTL = 30;
    private const MIN_CORPUS_ITEMS = 5; // for stage 3 — corpus seeded

    public function forWorkspace(Workspace $workspace): WorkspaceReadiness
    {
        $brands = $workspace->brands()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        $brandReadinesses = $brands->map(fn (Brand $b) => $this->forBrand($b))->all();

        return new WorkspaceReadiness($workspace, ...$brandReadinesses);
    }

    public function forBrand(Brand $brand): BrandReadiness
    {
        $cacheKey = "readiness:brand:{$brand->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($brand) {
            return $this->computeBrandReadiness($brand);
        });
    }

    /** Force a fresh detector run — call this after any state-changing action. */
    public function invalidate(Brand $brand): void
    {
        Cache::forget("readiness:brand:{$brand->id}");
    }

    private function computeBrandReadiness(Brand $brand): BrandReadiness
    {
        $stages = [
            $this->stage1_brandCreated($brand),
            $this->stage2_brandStyle($brand),
            $this->stage3_corpusSeeded($brand),
            $this->stage4_platformConnected($brand),
            $this->stage5_autonomyDecided($brand),
            $this->stage6_calendarGenerated($brand),
            $this->stage7_complianceApprovedDraft($brand),
            $this->stage8_postScheduled($brand),
            $this->stage9_metricsRecorded($brand),
        ];

        return new BrandReadiness($brand, ...$stages);
    }

    // ─── DETECTORS ────────────────────────────────────────────────────────

    private function stage1_brandCreated(Brand $brand): ReadinessStage
    {
        // If we got a Brand instance, the row exists by definition.
        return new ReadinessStage(
            id: 'brand_created',
            order: 1,
            label: 'Brand profile created',
            description: 'The brand record exists in your workspace and is ready to be configured.',
            done: true,
            skippable: false,
            ctaLabel: 'View brand profile',
            ctaUrl: $this->cta('brand_profile', $brand),
            blockedBy: null,
            evidence: 'Created '.optional($brand->created_at)->diffForHumans(),
        );
    }

    private function stage2_brandStyle(Brand $brand): ReadinessStage
    {
        $style = $brand->styles()
            ->where('is_current', true)
            ->whereNotNull('embedding')
            ->latest()
            ->first();

        $done = $style !== null;
        $evidence = $style
            ? 'Synthesised '.$style->created_at->diffForHumans()
                . ' (v'.$style->version.', '.strlen($style->content_md).' chars)'
            : null;

        return new ReadinessStage(
            id: 'brand_style',
            order: 2,
            label: 'Brand voice synthesised',
            description: 'The Onboarding agent has scraped your evidence sources, synthesised a brand-style.md, and embedded it for retrieval.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'View brand style' : 'Run Onboarding agent',
            ctaUrl: $this->cta($done ? 'brand_style_view' : 'brand_onboarding', $brand),
            blockedBy: null,
            evidence: $evidence,
        );
    }

    private function stage3_corpusSeeded(Brand $brand): ReadinessStage
    {
        $count = BrandCorpusItem::where('brand_id', $brand->id)->count();
        $done = $count >= self::MIN_CORPUS_ITEMS;

        return new ReadinessStage(
            id: 'corpus_seeded',
            order: 3,
            label: 'Brand corpus seeded',
            description: 'At least '.self::MIN_CORPUS_ITEMS.' historical posts or website chunks indexed so the Writer can ground every caption in your real voice.',
            done: $done,
            skippable: true,
            ctaLabel: $done ? 'Manage corpus' : 'Seed corpus',
            ctaUrl: $this->cta('corpus', $brand),
            blockedBy: $brand->styles()->where('is_current', true)->exists() ? null : 'brand_style',
            evidence: $count > 0 ? "$count items indexed" : null,
        );
    }

    private function stage4_platformConnected(Brand $brand): ReadinessStage
    {
        $active = PlatformConnection::where('brand_id', $brand->id)
            ->where('status', 'active')
            ->get();

        $done = $active->isNotEmpty();
        $evidence = $done
            ? $active->map(fn ($c) => ucfirst($c->platform).' (@'.($c->display_handle ?: 'unknown').')')->implode(', ')
            : null;

        return new ReadinessStage(
            id: 'platform_connected',
            order: 4,
            label: 'At least one platform connected',
            description: 'Connect Instagram, LinkedIn, TikTok, X, Threads, or Facebook so the Scheduler can publish on your behalf.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'Manage connections' : 'Connect a platform',
            ctaUrl: $this->cta('platforms', $brand),
            blockedBy: null,
            evidence: $evidence,
        );
    }

    private function stage5_autonomyDecided(Brand $brand): ReadinessStage
    {
        $globalDefault = AutonomySetting::where('brand_id', $brand->id)
            ->whereNull('platform')
            ->first();

        $done = $globalDefault !== null;
        $evidence = $done
            ? 'Default lane: '.ucfirst($globalDefault->default_lane).' (configurable per platform)'
            : null;

        return new ReadinessStage(
            id: 'autonomy_decided',
            order: 5,
            label: 'Autonomy lane decided',
            description: 'Pick green (auto-publish), amber (1 human approves), or red (2 humans approve) as your default. You can override per platform later.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'Adjust autonomy' : 'Pick default lane',
            ctaUrl: $this->cta('autonomy', $brand),
            blockedBy: null,
            evidence: $evidence,
        );
    }

    private function stage6_calendarGenerated(Brand $brand): ReadinessStage
    {
        $hasStyle = $brand->styles()->where('is_current', true)->exists();
        $hasPlatform = PlatformConnection::where('brand_id', $brand->id)
            ->where('status', 'active')
            ->exists();

        $blockedBy = ! $hasStyle ? 'brand_style' : (! $hasPlatform ? 'platform_connected' : null);

        $calendar = ContentCalendar::where('brand_id', $brand->id)
            ->whereIn('status', ['in_review', 'approved'])
            ->latest('period_starts_on')
            ->first();

        $done = $calendar !== null;
        $evidence = $done
            ? "Calendar: {$calendar->label} — ".$calendar->entries()->count().' entries planned'
            : null;

        return new ReadinessStage(
            id: 'calendar_generated',
            order: 6,
            label: 'First calendar generated',
            description: 'The Strategist agent builds a month of post ideas with pillar mix, format mix, and platform targeting.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'View calendar' : 'Run Strategist',
            ctaUrl: $this->cta('calendar', $brand),
            blockedBy: $blockedBy,
            evidence: $evidence,
        );
    }

    private function stage7_complianceApprovedDraft(Brand $brand): ReadinessStage
    {
        $hasCalendar = ContentCalendar::where('brand_id', $brand->id)
            ->whereIn('status', ['in_review', 'approved'])
            ->exists();

        $blockedBy = ! $hasCalendar ? 'calendar_generated' : null;

        $draft = Draft::where('brand_id', $brand->id)
            ->whereIn('status', ['awaiting_approval', 'approved', 'scheduled', 'published'])
            ->latest()
            ->first();

        $done = $draft !== null;
        $evidence = $draft
            ? 'Latest: '.ucfirst($draft->platform).' ('.$draft->status.') — '.$draft->created_at->diffForHumans()
            : null;

        return new ReadinessStage(
            id: 'first_draft_passed',
            order: 7,
            label: 'First draft passes Compliance',
            description: 'The Writer drafts. The Compliance gate checks brand-voice, factual grounding, embargoes, dedup, and image-DNA. A draft hits this stage only when every check passes.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'Review drafts' : 'Run Writer',
            ctaUrl: $this->cta('drafts', $brand),
            blockedBy: $blockedBy,
            evidence: $evidence,
        );
    }

    private function stage8_postScheduled(Brand $brand): ReadinessStage
    {
        $hasApprovedDraft = Draft::where('brand_id', $brand->id)
            ->whereIn('status', ['approved', 'scheduled', 'published'])
            ->exists();

        $blockedBy = ! $hasApprovedDraft ? 'first_draft_passed' : null;

        $scheduled = ScheduledPost::where('brand_id', $brand->id)
            ->whereIn('status', ['queued', 'submitted', 'submitting', 'published'])
            ->latest()
            ->first();

        $done = $scheduled !== null;
        $evidence = $scheduled
            ? 'Scheduled for '.$scheduled->scheduled_for->format('M j, H:i').' ('.$scheduled->status.')'
            : null;

        return new ReadinessStage(
            id: 'post_scheduled',
            order: 8,
            label: 'First post scheduled',
            description: 'An approved draft is queued for publishing via Blotato. Tiered autonomy is active.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'View schedule' : 'Approve & schedule a draft',
            ctaUrl: $this->cta('schedule', $brand),
            blockedBy: $blockedBy,
            evidence: $evidence,
        );
    }

    private function stage9_metricsRecorded(Brand $brand): ReadinessStage
    {
        $hasScheduled = ScheduledPost::where('brand_id', $brand->id)
            ->whereIn('status', ['submitted', 'published'])
            ->exists();

        $blockedBy = ! $hasScheduled ? 'post_scheduled' : null;

        $upload = PerformanceUpload::where('brand_id', $brand->id)->latest()->first();
        $publishedWithMetrics = ScheduledPost::where('brand_id', $brand->id)
            ->where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->count();

        $done = $upload !== null || $publishedWithMetrics > 0;

        $evidenceParts = [];
        if ($upload) $evidenceParts[] = "{$upload->source} uploaded ".$upload->created_at->diffForHumans();
        if ($publishedWithMetrics > 0) $evidenceParts[] = "$publishedWithMetrics published post(s)";
        $evidence = $done ? implode(' · ', $evidenceParts) : null;

        return new ReadinessStage(
            id: 'metrics_recorded',
            order: 9,
            label: 'First real metric recorded',
            description: 'Either a post has published successfully (platform post id captured) or a CSV / manual metric has been uploaded. No fabricated numbers — every metric is sourced.',
            done: $done,
            skippable: false,
            ctaLabel: $done ? 'View performance' : 'Upload first metrics',
            ctaUrl: $this->cta('performance', $brand),
            blockedBy: $blockedBy,
            evidence: $evidence,
        );
    }

    /**
     * Resolve the next-action URL for a stage. Where Filament resources exist
     * we route directly; otherwise we deep-link the wizard with a `?focus=`
     * so the user lands on the same stage and gets a clear "this is what comes
     * next" message even though we haven't shipped that page yet.
     */
    private function cta(string $action, Brand $brand): string
    {
        $brandId = $brand->id;
        $tryRoute = function (string $name, array $params) {
            try {
                return route($name, $params, false);
            } catch (\Throwable $e) {
                return null;
            }
        };

        $wizardFallback = fn (string $focus): string => $tryRoute('filament.agency.pages.setup-wizard', ['brand' => $brandId, 'focus' => $focus])
            ?? "/agency/setup-wizard?brand={$brandId}&focus={$focus}";

        return match ($action) {
            'brand_profile' => $tryRoute('filament.agency.resources.brands.edit', ['record' => $brandId])
                ?? $wizardFallback('brand_created'),
            'brand_onboarding' => $wizardFallback('brand_style'),
            'brand_style_view' => $wizardFallback('brand_style'),
            // Real /agency/brand-corpus page (BrandCorpusSeed). Customer
            // pastes historical posts or seeds from website. Deep-link with
            // ?brand=N so the page loads the focused brand directly.
            // Route name is `brand-corpus` (no -seed suffix) because the
            // page sets $slug = 'brand-corpus' which Filament uses to
            // derive both URL and route name.
            'corpus' => $tryRoute('filament.agency.pages.brand-corpus', ['brand' => $brandId])
                ?? $wizardFallback('corpus_seeded'),
            // Real /agency/platforms page exists (Filament resource:
            // PlatformConnectionResource). Deep-link with ?brand=N so the
            // Sync action targets the right brand. If the route name lookup
            // fails we fall back to the wizard so the user is never stranded.
            'platforms' => $tryRoute('filament.agency.resources.platform-connections.index', ['brand' => $brandId])
                ?? $wizardFallback('platform_connected'),
            // Real /agency/autonomy page (AutonomyLane). Customer picks
            // green / amber / red as the brand's default lane. Route name is
            // `autonomy` because the page sets $slug = 'autonomy'.
            'autonomy' => $tryRoute('filament.agency.pages.autonomy', ['brand' => $brandId])
                ?? $wizardFallback('autonomy_decided'),
            // Real Filament Resource pages — auto-discovered, route names
            // follow the {plural-kebab} pattern.
            'calendar' => $tryRoute('filament.agency.resources.calendar-entries.index', ['brand' => $brandId])
                ?? $wizardFallback('calendar_generated'),
            'drafts' => $tryRoute('filament.agency.resources.drafts.index', ['brand' => $brandId])
                ?? $wizardFallback('first_draft_passed'),
            'schedule' => $tryRoute('filament.agency.resources.scheduled-posts.index', ['brand' => $brandId])
                ?? $wizardFallback('post_scheduled'),
            'performance' => $wizardFallback('metrics_recorded'),
            default => $wizardFallback($action),
        };
    }
}
