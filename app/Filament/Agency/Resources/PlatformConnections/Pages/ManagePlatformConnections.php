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
        // This page is own-workspace for EVERYONE now, including HQ (the
        // cross-tenant super-admin view moved to /admin →
        // ClientPlatformConnectionResource on 2026-06-02). So no HQ-special
        // copy here — HQ sees its own platforms exactly like any customer.
        if ($this->workspaceHasNoMappedBrand()) {
            return 'Your brand needs its secure space set up before social accounts can be connected. '
                . 'Head to Platform setup to request it — our team sets it up, then sends you a secure connect-link.';
        }

        return 'These are the social accounts detected as connected for your brand. '
            . 'To add a platform, use the connect-link in Platform setup; then click "Refresh connections" here.';
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
                ->modalDescription('Social accounts connect through a secure connect-link, then appear here once detected.')
                ->modalContent(fn () => view('filament.agency.modals.connect-metricool'))
                ->modalWidth('lg')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('refresh')
                ->label('Refresh connections')
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
                ->title('Brand not set up yet')
                ->body('This brand\'s secure space isn\'t set up yet. Go to Platform setup to request it — once it\'s ready, you\'ll get a connect-link.')
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
                ->body('No connected networks for "' . $brand->name . '" yet. '
                    . 'If you just connected via the link, give it a minute and refresh again.')
                ->warning()
                ->send();
            return;
        }

        Notification::make()
            ->title('Connections refreshed')
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

    /**
     * The customer's OWN brand connect-link — the durable per-brand Metricool
     * share-link (https://f.mtr.cool/...) where they actually add/edit their
     * social accounts at the source. Stored on the brand by
     * brand:send-metricool-link / brand:set-metricool-blog --connect-url and
     * read back via Brand::metricoolManageUrl() (null-safe: returns null unless
     * it's a valid https URL).
     *
     * Returns null when no link is stored yet — the modal then falls back to the
     * Platform setup wizard ("request a fresh one") rather than dead-ending the
     * arrow. We never invent an app.metricool.com URL: that dashboard is one
     * shared agency account across all tenants and the customer has no login
     * there, so the only safe customer destination is this brand's own link.
     */
    public function connectLink(): ?string
    {
        $brand = $this->resolveBrandForSync();

        return $brand?->metricoolManageUrl();
    }
}
