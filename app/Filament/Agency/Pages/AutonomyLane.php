<?php

namespace App\Filament\Agency\Pages;

use App\Models\AutonomySetting;
use App\Models\Brand;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Readiness\SetupReadiness;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Stage 05 — Autonomy lane decided.
 *
 * Picks the brand's default autonomy lane which the Compliance gate uses
 * to decide how a draft moves from Writer → Scheduled:
 *
 *   green  — auto-publish (no human approval)
 *   amber  — 1 human approves
 *
 * One row in autonomy_settings with platform IS NULL counts as the global
 * default; per-platform overrides (e.g. green on Threads, amber on LinkedIn)
 * are added later via separate platform-scoped rows.
 *
 * Note: the schema CHECK constraint still permits 'red' (legacy two-human
 * approval). The UI no longer offers red because we never built the second-
 * approver gate — Approve flips status to 'approved' on the first click
 * regardless of lane, so red and amber are functionally identical. The
 * picker silently rewrites any pre-existing red rows to amber on switch.
 */
class AutonomyLane extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Autonomy';
    protected static ?string $title = 'Autonomy lane';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'autonomy';
    protected string $view = 'filament.agency.pages.autonomy-lane';

    public function getSubheading(): ?string
    {
        return 'How much the AI ships alone — green publishes itself, amber needs one approval, red needs two.';
    }

    /** Livewire-safe scalar state. */
    public ?int $brand = null;
    public ?string $currentLane = null;

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
        $this->refreshState();
    }

    private function refreshState(): void
    {
        $brand = $this->resolveBrand();
        if (! $brand) return;

        $row = AutonomySetting::where('brand_id', $brand->id)
            ->whereNull('platform')
            ->first();
        // Legacy 'red' rows surface as 'amber' in the UI — the second-approver
        // gate was never built, so red has always behaved like amber at runtime.
        $stored = $row?->default_lane;
        $this->currentLane = $stored === 'red' ? 'amber' : $stored;
    }

    public function resolveBrand(): ?Brand
    {
        $user = auth()->user();
        if (! $user) return null;

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) return null;

        if ($this->brand) {
            $b = Brand::where('workspace_id', $ws->id)->find($this->brand);
            if ($b) return $b;
        }

        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
    }

    public function pickLane(string $lane): void
    {
        // We removed the red lane from the UI on 2026-05-24 because the
        // second-approver gate was never built — red behaved identically to
        // amber at runtime. Reject defensively in case a stale client posts it.
        if (! in_array($lane, ['green', 'amber'], true)) {
            Notification::make()->title('Unknown lane')->danger()->send();
            return;
        }

        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to set')->danger()->send();
            return;
        }

        $previous = AutonomySetting::where('brand_id', $brand->id)
            ->whereNull('platform')
            ->value('default_lane');

        // Idempotent guard: no-op if the lane is unchanged. We DO still write
        // when previous === 'red' so we transparently upgrade the legacy row.
        $effectivePrev = $previous === 'red' ? 'amber' : $previous;
        if ($effectivePrev === $lane) {
            Notification::make()
                ->title(ucfirst($lane).' is already your default')
                ->body('Nothing to change for '.$brand->name.'.')
                ->send();
            return;
        }

        AutonomySetting::updateOrCreate(
            ['brand_id' => $brand->id, 'platform' => null],
            ['default_lane' => $lane],
        );

        app(SetupReadiness::class)->invalidate($brand);
        $this->currentLane = $lane;

        // Count in-flight drafts that the switch affects, so the operator
        // knows exactly what just changed. Lane is snapshotted onto the draft
        // at Writer time — switching the brand default does NOT retroactively
        // re-route already-stamped drafts, so we surface the counts honestly.
        $affected = $this->affectedDraftCounts($brand, $lane);

        Notification::make()
            ->title('Default lane: '.ucfirst($lane))
            ->body($this->switchBodyCopy($brand->name, $previous, $lane, $affected))
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Count drafts whose handling changes — or doesn't — under the new lane.
     * Returns an associative array:
     *
     *   pending_approval  — drafts currently awaiting a human click. If the new
     *                       lane is GREEN, the operator may want to bulk-approve
     *                       these; if AMBER, nothing new for them.
     *   future_new        — informational: only the lane stamped at Writer time
     *                       matters, so this is "what's coming next" not "what
     *                       changes now". We surface 0 here intentionally.
     */
    private function affectedDraftCounts(Brand $brand, string $newLane): array
    {
        $pendingApproval = Draft::where('brand_id', $brand->id)
            ->where('status', 'awaiting_approval')
            ->count();

        return [
            'pending_approval' => $pendingApproval,
            'new_lane' => $newLane,
        ];
    }

    private function switchBodyCopy(string $brandName, ?string $previous, string $newLane, array $affected): string
    {
        $pending = $affected['pending_approval'];

        // Switch direction shapes the message — loosening (→ green) is the one
        // where pending drafts get stranded; tightening (→ amber) just changes
        // what future drafts do.
        if ($newLane === 'green') {
            $lead = "Future drafts for {$brandName} will skip approval and auto-schedule once Compliance passes.";
            if ($pending > 0) {
                return $lead." Heads up: {$pending} draft(s) are still waiting for approval under the old lane — open Drafts and approve them, or they'll sit there indefinitely.";
            }
            return $lead.' No drafts are currently waiting on approval, so nothing is stranded.';
        }

        // newLane === 'amber'
        $lead = "Future drafts for {$brandName} will land in the Drafts queue and wait for one human approval before scheduling.";
        if ($previous === 'green') {
            return $lead.' Any drafts that were already auto-approved under Green are unaffected — they remain approved and will publish on schedule.';
        }
        if ($previous === 'red') {
            return $lead.' We retired the two-approver red lane (it behaved identically to amber). One approval is enough.';
        }
        return $lead;
    }
}
