<?php

namespace App\Filament\Agency\Resources\PlatformConnections\Pages;

use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use App\Services\Blotato\PlatformSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePlatformConnections extends ManageRecords
{
    protected static string $resource = PlatformConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openBlotato')
                ->label('Open Blotato to add a platform')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->outlined()
                ->url('https://my.blotato.com/accounts', shouldOpenInNewTab: true),

            Action::make('sync')
                ->label('Sync from Blotato')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function (): void {
                    $brand = $this->resolveBrandForSync();
                    if (! $brand) {
                        Notification::make()
                            ->title('No brand to sync against')
                            ->body('Create a brand first, then connect platforms.')
                            ->warning()
                            ->send();
                        return;
                    }

                    try {
                        $client = BlotatoClient::fromConfig();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Blotato configuration error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }

                    $result = (new PlatformSyncService($client))->syncForBrand($brand);

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
                }),
        ];
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
