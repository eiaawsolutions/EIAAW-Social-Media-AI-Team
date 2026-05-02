<?php

namespace App\Filament\Agency\Pages;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Agents\OnboardingAgent;
use App\Agents\StrategistAgent;
use App\Agents\WriterAgent;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Exceptions\AgentPrerequisiteMissing;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Readiness\BrandReadiness;
use App\Services\Readiness\SetupReadiness;
use App\Services\Readiness\WorkspaceReadiness;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Setup Wizard — the verifiable readiness ladder.
 *
 * This page is the default landing for any workspace that is < 100% ready.
 * It runs SetupReadiness against Postgres on every render (cached 30s) and
 * shows the user exactly what's set up, what's still missing, and the single
 * next action they should take.
 *
 * URL: /agency/setup-wizard?brand=<id>&focus=<stage_id>
 */
class SetupWizard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Setup wizard';
    protected static ?string $title = 'Setup wizard';
    protected static ?int $navigationSort = -1; // pin to top of nav
    protected string $view = 'filament.agency.pages.setup-wizard';

    /** Livewire-safe scalar props (these CAN round-trip to the browser). */
    public ?int $brand = null;
    public ?string $focus = null;

    /**
     * Livewire v4 refuses to serialise complex objects in public state. We keep
     * the readiness objects protected and recompute on every render — both
     * mount() and any wire:click action will redundantly call refreshReadiness().
     */
    protected ?WorkspaceReadiness $workspaceReadiness = null;
    protected ?BrandReadiness $brandReadiness = null;

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
        $this->focus = request()->string('focus')->toString() ?: null;
        $this->refreshReadiness();
    }

    public function refreshReadiness(): void
    {
        $user = auth()->user();
        if (! $user) return;

        $workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $service = app(SetupReadiness::class);
        $this->workspaceReadiness = $service->forWorkspace($workspace);

        // Pick which brand the wizard is focused on
        if ($this->brand) {
            $brand = Brand::where('workspace_id', $workspace->id)->find($this->brand);
            if ($brand) {
                $this->brandReadiness = $service->forBrand($brand);
                return;
            }
        }

        // Default: first incomplete brand, or first brand
        $this->brandReadiness = $this->workspaceReadiness->nextActionableBrand()
            ?? $this->workspaceReadiness->primaryBrand;
    }

    /** Public accessors used by the Blade view. */
    public function workspaceReadiness(): ?WorkspaceReadiness
    {
        if ($this->workspaceReadiness === null) {
            $this->refreshReadiness();
        }
        return $this->workspaceReadiness;
    }

    public function brandReadiness(): ?BrandReadiness
    {
        if ($this->brandReadiness === null && $this->workspaceReadiness === null) {
            $this->refreshReadiness();
        }
        return $this->brandReadiness;
    }

    public function getHeading(): string|Htmlable
    {
        if (! $this->workspaceReadiness || ! $this->workspaceReadiness->hasAnyBrand) {
            return 'Welcome — let\'s set up your first brand';
        }
        if ($this->brandReadiness?->isComplete) {
            return $this->brandReadiness->brand->name . ' — fully set up';
        }
        return $this->brandReadiness
            ? $this->brandReadiness->brand->name . ' — ' . $this->brandReadiness->percent . '% ready'
            : 'Setup wizard';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->workspaceReadiness || ! $this->workspaceReadiness->hasAnyBrand) {
            return 'Two minutes to a brand profile. Six more to your first compliant draft. Then we run.';
        }
        $next = $this->brandReadiness?->nextActionable;
        if (! $next) {
            return 'Every stage complete. The agents have everything they need to run.';
        }
        return 'Next: ' . $next->label;
    }

    /** Used by Blade view: resolves the right CSS class for a stage row. */
    public function statusClass(string $status): string
    {
        return match ($status) {
            'done' => 'wizard-stage-done',
            'blocked' => 'wizard-stage-blocked',
            default => 'wizard-stage-todo',
        };
    }

    public function statusIcon(string $status): string
    {
        return match ($status) {
            'done' => '✓',
            'blocked' => '·',
            default => '○',
        };
    }

    /**
     * Livewire entry point — wired from the Blade CTA buttons.
     * Dispatches the right agent based on the stage id, surfaces a
     * Filament notification with the result, then refreshes readiness.
     */
    public function runStage(string $stageId): void
    {
        // Agents make outbound HTTP (website scrape + Claude + embeddings) that
        // can total >30s. PHP-FPM's default max_execution_time of 30s kills the
        // request mid-flight — Livewire then can't deserialize the partial
        // response and the browser shows "Error while loading page" instead of
        // any real notification. Lift the wall-clock for this action only.
        @set_time_limit(180);

        // Livewire v4 doesn't rehydrate `protected` readiness objects between
        // requests (per the class-level comment on $brandReadiness). mount()
        // only fires on the initial page load — wire:click actions skip it.
        // Recompute readiness here so we can resolve the focused brand from
        // the public $brand id that Livewire DOES round-trip.
        $this->refreshReadiness();

        if (! $this->brandReadiness) {
            Notification::make()->title('No brand selected')->danger()->send();
            return;
        }

        $brand = $this->brandReadiness->brand;

        try {
            $result = match ($stageId) {
                'brand_style' => app(OnboardingAgent::class)->run($brand),
                'calendar_generated' => app(StrategistAgent::class)->run($brand),
                'first_draft_passed' => $this->runFirstDraft($brand),
                'post_scheduled' => $this->runFirstSchedule($brand),
                default => null,
            };

            if ($result === null) {
                Notification::make()
                    ->title('Coming soon')
                    ->body("Stage \"$stageId\" doesn't have a one-click agent yet — finish the prerequisite stages first.")
                    ->warning()
                    ->send();
                return;
            }

            if ($result->ok) {
                Notification::make()
                    ->title('Done — '.$stageId.' complete')
                    ->body($this->summariseResult($stageId, $result))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Could not complete stage')
                    ->body($result->errorMessage ?: 'The agent returned no result. Try again.')
                    ->danger()
                    ->persistent()
                    ->send();
            }
        } catch (AgentPrerequisiteMissing $e) {
            Notification::make()
                ->title('Missing prerequisite')
                ->body('Complete stage "'.($e->missingStage() ?? 'unknown').'" first.')
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Agent crashed')
                ->body(substr($e->getMessage(), 0, 300))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->refreshReadiness();
    }

    private function summariseResult(string $stageId, \App\Agents\AgentResult $result): string
    {
        $data = $result->data;
        return match ($stageId) {
            'brand_style' => sprintf(
                'Brand voice synthesised — v%d, %d words, %d evidence quotes.',
                $data['version'] ?? 1,
                $data['word_count'] ?? 0,
                $data['evidence_count'] ?? 0,
            ),
            'calendar_generated' => sprintf(
                '%s calendar built — %d entries.',
                $data['label'] ?? 'Month',
                $data['entry_count'] ?? 0,
            ),
            'first_draft_passed' => sprintf(
                'First draft written and passed Compliance — %s on %s (%s lane).',
                substr($data['body_preview'] ?? '', 0, 80),
                $data['platform'] ?? '?',
                $data['lane'] ?? '?',
            ),
            'post_scheduled' => sprintf(
                'Scheduled draft #%d to %s for %s (status: %s).',
                $data['draft_id'] ?? 0,
                $data['platform'] ?? '?',
                $data['scheduled_for'] ?? '?',
                $data['status'] ?? '?',
            ),
            default => 'Stage completed.',
        };
    }

    /**
     * Stage 07 wiring — picks the first calendar entry, runs Writer on the
     * entry's first targeted platform, then runs Compliance on the resulting
     * draft. The detector flips to done when a draft reaches status
     * awaiting_approval / approved / scheduled / published. Compliance
     * failures surface with their reason so the user can iterate.
     */
    private function runFirstDraft(\App\Models\Brand $brand): \App\Agents\AgentResult
    {
        $entry = CalendarEntry::where('brand_id', $brand->id)
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->first();

        if (! $entry) {
            return \App\Agents\AgentResult::fail('No calendar entries yet — run the Strategist first.');
        }

        $platforms = is_array($entry->platforms) ? $entry->platforms : [];
        $platform = $platforms[0] ?? null;
        if (! $platform) {
            return \App\Agents\AgentResult::fail('Calendar entry has no platforms — re-run the Strategist.');
        }

        $writerResult = app(WriterAgent::class)->run($brand, [
            'calendar_entry_id' => $entry->id,
            'platform' => $platform,
        ]);

        if (! $writerResult->ok) {
            return $writerResult;
        }

        $draftId = $writerResult->data['draft_id'] ?? null;
        if (! $draftId) {
            return \App\Agents\AgentResult::fail('Writer returned no draft id.');
        }

        // Generate the image. Soft-fail: if FAL/Blotato errors, the draft
        // still exists as text-only and the user can regenerate later via
        // /agency/drafts. Stage 07 doesn't gate on imagery.
        try {
            app(DesignerAgent::class)->run($brand, ['draft_id' => $draftId]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SetupWizard: Designer crashed (draft kept as text-only)', [
                'draft_id' => $draftId,
                'error' => $e->getMessage(),
            ]);
        }

        $complianceResult = app(ComplianceAgent::class)->run($brand, [
            'draft_id' => $draftId,
        ]);

        // If compliance failed, surface its reason. The Writer draft still
        // exists in compliance_failed — user can iterate, but Stage 07 does
        // not flip until a draft passes.
        if (! $complianceResult->ok) {
            return \App\Agents\AgentResult::fail(
                'Draft written but Compliance errored: ' . ($complianceResult->errorMessage ?? 'unknown'),
            );
        }

        if (empty($complianceResult->data['all_passed'])) {
            $failed = collect($complianceResult->data['checks'] ?? [])
                ->where('result', 'fail')
                ->pluck('type')
                ->implode(', ');
            return \App\Agents\AgentResult::fail(
                'Draft written but failed Compliance: ' . ($failed ?: 'unknown') . '. Iterate and try again.',
            );
        }

        return \App\Agents\AgentResult::ok(
            array_merge($writerResult->data, [
                'lane' => $writerResult->data['lane'] ?? 'amber',
                'compliance_status' => $complianceResult->data['new_status'] ?? 'awaiting_approval',
            ]),
            $writerResult->meta,
        );
    }

    /**
     * Stage 08 wiring — approve the latest compliance-passed draft and queue
     * it for publishing 1 hour from now. Stage detector flips when a row
     * exists in scheduled_posts with status in queued/submitting/submitted/published.
     *
     * Why 1 hour: a non-zero offset is required so the SchedulerWorker has
     * a window to pick the row up; in v2 the operator picks an exact time.
     * On amber/red lanes a draft only reaches 'awaiting_approval' — Stage 08
     * implicitly approves the latest one (this is the "first" post path —
     * subsequent scheduling lives on the per-draft review surface, v1.1).
     */
    private function runFirstSchedule(\App\Models\Brand $brand): \App\Agents\AgentResult
    {
        // Pick the most recent draft that's at least compliance-passed. Both
        // 'approved' (green lane auto) and 'awaiting_approval' (amber/red,
        // human-not-yet-clicked) qualify — Stage 08 is the moment the
        // founder approves the first one.
        $draft = Draft::where('brand_id', $brand->id)
            ->whereIn('status', ['awaiting_approval', 'approved'])
            ->latest()
            ->first();

        if (! $draft) {
            return \App\Agents\AgentResult::fail('No compliance-passed draft yet — run Stage 07 first.');
        }

        $connection = PlatformConnection::where('brand_id', $brand->id)
            ->where('platform', $draft->platform)
            ->where('status', 'active')
            ->first();

        if (! $connection) {
            return \App\Agents\AgentResult::fail(sprintf(
                'No active %s connection for this brand. Reconnect on /agency/platform-connections.',
                $draft->platform,
            ));
        }

        // Idempotent: don't double-queue if Stage 08 already passed.
        $existing = ScheduledPost::where('draft_id', $draft->id)
            ->whereIn('status', ['queued', 'submitting', 'submitted', 'published'])
            ->first();

        if ($existing) {
            return \App\Agents\AgentResult::ok([
                'draft_id' => $draft->id,
                'platform' => $draft->platform,
                'scheduled_for' => $existing->scheduled_for->format('M j, H:i'),
                'status' => $existing->status,
                'note' => 'already-scheduled',
            ]);
        }

        $scheduledFor = now()->addHour();

        $post = ScheduledPost::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'platform_connection_id' => $connection->id,
            'scheduled_for' => $scheduledFor,
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        // Flip the draft to 'scheduled' so Stage 07's detector keeps showing
        // green and the draft list reflects that this draft is queued.
        $draft->update(['status' => 'scheduled']);

        app(\App\Services\Readiness\SetupReadiness::class)->invalidate($brand);

        return \App\Agents\AgentResult::ok([
            'draft_id' => $draft->id,
            'scheduled_post_id' => $post->id,
            'platform' => $draft->platform,
            'scheduled_for' => $scheduledFor->format('M j, H:i'),
            'status' => $post->status,
        ]);
    }
}
