<?php

namespace App\Filament\Agency\Resources\PlatformConnections\Pages;

use App\Filament\Agency\Pages\MetricoolSetup;
use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Metricool\MetricoolClient;
use App\Services\Metricool\MetricoolConnectionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

/**
 * Platforms — read-only-ish view of the social accounts a brand has connected,
 * detected live from Metricool's /admin/profile (NOT Blotato anymore).
 *
 * Connection model (post Blotato decommission — PublisherFactory is now
 * Metricool-only): the customer connects their socials via a Metricool
 * connect-link in the "Platform setup" wizard (MetricoolSetup). Metricool is
 * natively multi-brand, so each SMT brand maps to a Metricool brand via
 * brands.metricool_blog_id, and "what's connected" is read back per brand from
 * /admin/profile. This page therefore:
 *
 *   - lists detected connections (one row per connected network per brand)
 *   - "Refresh from Metricool" header action — re-reads /admin/profile and
 *     upserts platform_connections via MetricoolConnectionService::sync()
 *   - points the customer at the wizard to ADD a platform (the connect-link
 *     step has no API; it lives in MetricoolSetup)
 *   - keeps per-connection "Target overrides" (still consumed verbatim by
 *     MetricoolPublisher::perNetworkData at publish time)
 *
 * Unlike the old Blotato flow there is no "open a broker tab and auto-poll for
 * 5 minutes" — Metricool connection is link-based and brand-scoped, so detection
 * is an explicit, honest read rather than a background guess.
 */
class ManagePlatformConnections extends ManageRecords
{
    protected static string $resource = PlatformConnectionResource::class;

    public function getSubheading(): ?string
    {
        if ($this->workspaceHasNoMappedBrand()) {
            return 'Your brand needs to be linked to Metricool before social accounts can be connected. '
                . 'Head to Platform setup to request it — our team maps it, then sends you a secure connect-link.';
        }

        return 'These are the social accounts detected as connected in Metricool for your brand. '
            . 'To add a platform, use the connect-link in Platform setup; then click "Refresh from Metricool" here.';
    }

    /**
     * True when no brand in the workspace has been mapped to a Metricool brand
     * yet (no metricool_blog_id anywhere). Drives the subheading + empty-state
     * guidance toward the setup wizard.
     */
    public function workspaceHasNoMappedBrand(): bool
    {
        $ws = $this->resolveCurrentWorkspace();
        if (! $ws instanceof Workspace) {
            return true;
        }

        return ! $ws->brands()
            ->whereNull('archived_at')
            ->whereNotNull('metricool_blog_id')
            ->exists();
    }

    private function resolveCurrentWorkspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addPlatform')
                ->label('Connect a platform')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->modalHeading('Connect a social platform')
                ->modalDescription('Social accounts connect through a secure Metricool connect-link, then appear here once detected.')
                ->modalContent(fn () => view('filament.agency.modals.connect-metricool'))
                ->modalWidth('lg')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('refresh')
                ->label('Refresh from Metricool')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->outlined()
                ->action(fn () => $this->runSync()),
        ];
    }

    /**
     * Re-read the brand's Metricool profile and mirror connected networks into
     * platform_connections. Single code path for the "Refresh" button.
     *
     * Mirrors MetricoolSetup::checkConnection but framed as a list refresh
     * rather than first-time onboarding (no metricool_connected_at stamping —
     * the wizard owns the "first connected" milestone).
     */
    public function runSync(): void
    {
        $brand = $this->resolveBrandForSync();
        if (! $brand) {
            Notification::make()
                ->title('No brand to refresh')
                ->body('Create a brand first, then connect platforms in Platform setup.')
                ->warning()
                ->send();
            return;
        }

        if (empty($brand->metricool_blog_id)) {
            Notification::make()
                ->title('Brand not linked to Metricool yet')
                ->body('This brand isn\'t mapped to a Metricool brand. Go to Platform setup to request setup — once mapped, you\'ll get a connect-link.')
                ->warning()
                ->persistent()
                ->send();
            return;
        }

        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            Notification::make()
                ->title('Refresh unavailable')
                ->body('Our publishing integration isn\'t configured right now. Email eiaawsolutions@gmail.com and we\'ll sort it.')
                ->danger()
                ->send();
            return;
        }

        try {
            $result = (new MetricoolConnectionService($client))->sync($brand);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Couldn\'t refresh right now')
                ->body('Please try again in a moment. If it keeps failing, email eiaawsolutions@gmail.com.')
                ->danger()
                ->send();
            return;
        }

        if (empty($result['networks'])) {
            Notification::make()
                ->title('No connected accounts found')
                ->body('Metricool reports no connected networks for "' . $brand->name . '" yet. '
                    . 'If you just connected via the link, give it a minute and refresh again.')
                ->warning()
                ->send();
            return;
        }

        Notification::make()
            ->title('Refreshed from Metricool')
            ->body(sprintf(
                'Brand "%s": %d connected, %d marked revoked.',
                $brand->name,
                $result['synced'],
                $result['revoked'],
            ))
            ->success()
            ->send();
    }

    /**
     * Pick which brand the refresh runs against. v1 model: the customer's
     * current workspace's first non-archived brand. When the wizard deep-links
     * here with ?brand=N we honour that.
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

        $ws = $this->resolveCurrentWorkspace();
        if (! $ws instanceof Workspace) {
            return null;
        }

        // Prefer a brand that's actually mapped to Metricool, so the refresh has
        // something to read; fall back to the first brand for a clear "not
        // mapped yet" message.
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderByRaw('metricool_blog_id IS NULL')
            ->orderBy('id')
            ->first();
    }

    private function brandIsInCurrentWorkspace(Brand $brand): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        $wsId = $user->current_workspace_id ?? $user->ownedWorkspaces()->value('id');
        return $wsId !== null && (int) $brand->workspace_id === (int) $wsId;
    }

    /** Deep-link target for "go set up a platform" — the Metricool wizard. */
    public function setupUrl(): string
    {
        return MetricoolSetup::getUrl();
    }
}
