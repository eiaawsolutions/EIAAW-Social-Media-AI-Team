<?php

namespace App\Filament\Resources\ClientPlatformConnections\Pages;

use App\Filament\Resources\ClientPlatformConnections\ClientPlatformConnectionResource;
use App\Models\Brand;
use App\Services\Metricool\MetricoolConnectionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageClientPlatformConnections extends ManageRecords
{
    protected static string $resource = ClientPlatformConnectionResource::class;

    public function getSubheading(): ?string
    {
        return 'Every client\'s social connections, across all workspaces, labelled by brand and owner. Use "Refresh from Metricool" to re-read a brand\'s connection state, or set per-network target overrides. Your own HQ platforms live in your Agency account.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Re-read connection state for a chosen client brand from Metricool's
            // /admin/profile (live detection, not a guess). Mirrors the customer
            // "Refresh connections" action but lets HQ pick any tenant's brand.
            Action::make('refreshFromMetricool')
                ->label('Refresh from Metricool')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\Select::make('brand_id')
                        ->label('Client brand')
                        ->required()
                        ->searchable()
                        ->options(fn () => Brand::query()
                            ->whereNull('archived_at')
                            ->whereNotNull('metricool_blog_id')
                            ->with('workspace')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Brand $b) => [
                                $b->id => $b->workspace
                                    ? sprintf('%s (%s)', $b->name, $b->workspace->name)
                                    : $b->name,
                            ])
                            ->all())
                        ->helperText('Only brands with a Metricool routing space (blogId) can be refreshed.'),
                ])
                ->action(function (array $data): void {
                    $brand = Brand::query()->find($data['brand_id']);
                    if (! $brand) {
                        Notification::make()->title('Brand not found')->danger()->send();

                        return;
                    }

                    try {
                        app(MetricoolConnectionService::class)->sync($brand);
                        Notification::make()
                            ->title('Refreshed')
                            ->body("Re-read {$brand->name}'s connections from Metricool.")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Refresh failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
