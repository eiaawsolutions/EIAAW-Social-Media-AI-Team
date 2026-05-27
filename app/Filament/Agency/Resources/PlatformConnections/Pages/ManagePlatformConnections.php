<?php

namespace App\Filament\Agency\Resources\PlatformConnections\Pages;

use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Blotato\PlatformSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePlatformConnections extends ManageRecords
{
    protected static string $resource = PlatformConnectionResource::class;

    /**
     * Wall-clock time the auto-sync poll window opened. Set by the
     * "Connect a platform" action; cleared after 5 minutes. Lives on the
     * Livewire component instance, so it survives across poll ticks
     * within the same page session.
     */
    public ?int $autoSyncStartedAt = null;

    public function getSubheading(): ?string
    {
        if ($this->workspaceNeedsBlotatoSetup()) {
            return 'Your workspace needs its own Blotato account before social platforms can be connected. Contact EIAAW support to provision one.';
        }
        return 'Connect your social accounts. We use Blotato as the OAuth broker — once you connect there, the account appears here automatically.';
    }

    /**
     * Surfaced to the connect-blotato blade so the modal can render the
     * right copy (action vs. blocked) per workspace.
     */
    public function workspaceNeedsBlotatoSetup(): bool
    {
        $ws = $this->resolveCurrentWorkspace();
        return $ws === null || $ws->needsBlotatoSetup();
    }

    public function workspaceBlotatoEmail(): ?string
    {
        return $this->resolveCurrentWorkspace()?->blotato_account_email;
    }

    private function resolveCurrentWorkspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    /**
     * Livewire polling interval. Returns null when no auto-sync window
     * is open, so the page is idle in steady state.
     */
    public function getPollingInterval(): ?string
    {
        if ($this->autoSyncStartedAt === null) {
            return null;
        }
        if ((time() - $this->autoSyncStartedAt) > 300) {
            $this->autoSyncStartedAt = null;
            return null;
        }
        return '5s';
    }

    /**
     * Polled side effect: run the sync silently if the auto-poll window
     * is open. Idempotent — PlatformSyncService upserts on
     * (brand_id, blotato_account_id), so repeat ticks while the user is
     * still in Blotato are safe and cheap.
     */
    public function autoSyncTick(): void
    {
        if ($this->autoSyncStartedAt === null) {
            return;
        }
        $this->runSync(silent: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openBlotato')
                ->label('Connect a platform')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('Connect a social platform')
                ->modalDescription('We use Blotato as the OAuth broker — they hold the approved app reviews from Meta, LinkedIn, TikTok, X, YouTube, Pinterest, Threads, and Bluesky. Click "Open Blotato" below to connect your account. When you come back, your new platform appears here automatically — no need to refresh.')
                ->modalContent(fn () => view('filament.agency.modals.connect-blotato'))
                ->modalWidth('lg')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->action(function (): void {
                    // Open the 5-minute auto-sync window. The modal's
                    // "Open Blotato" link opens my.blotato.com/settings
                    // in a new tab via target="_blank"; when the user
                    // returns, the Livewire poll has already pulled the
                    // new connection in.
                    $this->autoSyncStartedAt = time();
                }),

            Action::make('sync')
                ->label('Sync from Blotato')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->outlined()
                ->action(fn () => $this->runSync(silent: false)),
        ];
    }

    /**
     * Single sync code path used by the manual "Sync" button AND the
     * auto-poll tick. The `silent` flag suppresses notifications so the
     * background poll doesn't toast on every refresh (only the manual
     * button or a newly-added platform shows feedback).
     */
    public function runSync(bool $silent = false): void
    {
        $brand = $this->resolveBrandForSync();
        if (! $brand) {
            if (! $silent) {
                Notification::make()
                    ->title('No brand to sync against')
                    ->body('Create a brand first, then connect platforms.')
                    ->warning()
                    ->send();
            }
            return;
        }

        // Pre-flight: if this workspace hasn't been wired to its own Blotato
        // account yet, refuse rather than fall back to HQ's key (which would
        // leak cross-tenant accounts into this brand's platform_connections).
        // The user is shown an actionable "Connect your Blotato account"
        // message instead of an opaque sync error.
        if ($brand->workspace?->needsBlotatoSetup()) {
            if (! $silent) {
                Notification::make()
                    ->title('Blotato not connected for this workspace')
                    ->body('Your workspace needs its own Blotato account before social platforms can be synced. Contact your EIAAW administrator to provision one, or follow the setup guide at /agency/settings/integrations.')
                    ->warning()
                    ->persistent()
                    ->send();
            }
            return;
        }

        // PlatformSyncService now resolves the per-workspace BlotatoClient
        // internally — no client to inject. This is intentional: the prior
        // signature let callers pass a wrong-workspace client, which is
        // exactly the bug we just fixed.
        $result = (new PlatformSyncService())->syncForBrand($brand);

        if ($silent) {
            // Auto-poll: only toast when the sync actually changed
            // something. Otherwise the user gets a toast every 5s
            // while they're connecting — noisy and unhelpful.
            if ($result['synced'] > 0 || $result['marked_revoked'] > 0) {
                Notification::make()
                    ->title('Platform connected')
                    ->body(sprintf(
                        '%d new connection(s) detected for "%s".',
                        $result['synced'],
                        $brand->name,
                    ))
                    ->success()
                    ->send();
                // Stop polling once we got a result — the user has done
                // the thing. If they want to add another, they reopen
                // the modal.
                $this->autoSyncStartedAt = null;
            }
            return;
        }

        if ($result['errors']) {
            Notification::make()
                ->title('Sync completed with issues')
                ->body(
                    "Synced {$result['synced']}, marked {$result['marked_revoked']} revoked.\n"
                    . 'Errors: ' . implode('; ', $result['errors'])
                )
                ->warning()
                ->send();
            return;
        }

        Notification::make()
            ->title('Synced from Blotato')
            ->body(sprintf(
                'Brand "%s": %d connected, %d marked revoked.',
                $brand->name,
                $result['synced'],
                $result['marked_revoked'],
            ))
            ->success()
            ->send();
    }

    /**
     * Pick which brand the sync runs against. v1 model: the customer's
     * current workspace's first non-archived brand. When the wizard
     * deep-links here with ?brand=N we honour that.
     *
     * v1.1 will introduce a brand picker on this page so a multi-brand
     * agency can sync per-brand explicitly.
     */
    private function resolveBrandForSync(): ?Brand
    {
        $explicitBrandId = (int) request()->query('brand', 0);
        if ($explicitBrandId > 0) {
            $brand = Brand::find($explicitBrandId);
            if ($brand && $this->brandIsInCurrentWorkspace($brand)) {
                return $brand;
            }
        }

        $user = auth()->user();
        if (! $user) return null;

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) return null;

        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
    }

    private function brandIsInCurrentWorkspace(Brand $brand): bool
    {
        $user = auth()->user();
        if (! $user) return false;
        $wsId = $user->current_workspace_id ?? $user->ownedWorkspaces()->value('id');
        return $wsId !== null && (int) $brand->workspace_id === (int) $wsId;
    }
}
