<?php

namespace App\Filament\Agency\Resources\BrandAssets;

use App\Filament\Agency\Resources\BrandAssets\Pages\ManageBrandAssets;
use App\Models\BrandAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brand Assets — the upload library that DesignerAgent + VideoAgent pick
 * from BEFORE calling FAL. Zero-cost-per-post when the brand has stocked
 * the library; FAL fallback only for genuinely novel topics.
 *
 * Upload flow: drag-drop on the page → file lands on R2 (or local public
 * disk fallback) → BrandAssetTagger Job runs Claude vision to generate
 * description+tags → Voyage embeds → row becomes pickable by the Picker.
 */
class BrandAssetResource extends Resource
{
    protected static ?string $model = BrandAsset::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;
    protected static ?string $navigationLabel = 'Asset library';
    protected static ?string $modelLabel = 'Brand asset';
    protected static ?string $pluralModelLabel = 'Brand assets';
    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('public_url')
                    ->label('Preview')
                    ->size(64)
                    ->square()
                    ->defaultImageUrl(fn () => null),
                Tables\Columns\TextColumn::make('media_type')
                    ->badge()
                    ->color(fn (string $state) => $state === 'video' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Filename')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('— pending tagger —'),
                Tables\Columns\TextColumn::make('tags')
                    ->badge()
                    ->separator(',')
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(',', array_slice($state, 0, 5)) : '—'),
                Tables\Columns\IconColumn::make('brand_approved')
                    ->label('Approved')
                    ->boolean(),
                Tables\Columns\TextColumn::make('use_count')
                    ->label('Used')
                    ->color('gray')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->since()
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('never'),
                Tables\Columns\TextColumn::make('file_size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn ($state) => $state ? round($state / 1024 / 1024, 1) . ' MB' : '—')
                    ->color('gray')
                    ->size('sm'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('media_type')->options([
                    'image' => 'Images',
                    'video' => 'Videos',
                ]),
                Tables\Filters\TernaryFilter::make('brand_approved'),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (BrandAsset $r) => $r->original_filename ?? "Asset #{$r->id}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (BrandAsset $r) => view('filament.agency.partials.brand-asset-view', [
                        'asset' => $r,
                    ])),
                \Filament\Actions\Action::make('toggleApproved')
                    ->label(fn (BrandAsset $r) => $r->brand_approved ? 'Mark not approved' : 'Mark approved')
                    ->icon('heroicon-o-check-badge')
                    ->color(fn (BrandAsset $r) => $r->brand_approved ? 'gray' : 'success')
                    ->action(function (BrandAsset $r): void {
                        $r->update(['brand_approved' => ! $r->brand_approved]);
                        \Filament\Notifications\Notification::make()
                            ->title($r->brand_approved ? 'Approved' : 'Removed approval')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\Action::make('retag')
                    ->label('Re-tag')
                    ->icon('heroicon-o-tag')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Re-runs Claude vision to regenerate description + tags + embedding. ~1c per image, ~3s.')
                    ->action(function (BrandAsset $r): void {
                        @set_time_limit(120);
                        try {
                            app(\App\Services\Imagery\BrandAssetTagger::class)->tag($r);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Re-tag failed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();
                            return;
                        }
                        \Filament\Notifications\Notification::make()->title('Re-tagged')->success()->send();
                    }),
                \Filament\Actions\Action::make('archive')
                    ->label(fn (BrandAsset $r) => $r->archived_at ? 'Restore' : 'Archive')
                    ->icon(fn (BrandAsset $r) => $r->archived_at ? 'heroicon-o-arrow-uturn-up' : 'heroicon-o-archive-box')
                    ->color('gray')
                    ->action(function (BrandAsset $r): void {
                        $r->update(['archived_at' => $r->archived_at ? null : now()]);
                        \Filament\Notifications\Notification::make()->title($r->archived_at ? 'Archived' : 'Restored')->send();
                    }),
            ])
            ->emptyStateHeading('No assets yet')
            ->emptyStateDescription('Click "Upload assets" to add brand-approved images and videos. The Designer + Video agents will pick from these before falling back to AI generation.')
            ->emptyStateIcon(Heroicon::OutlinedPhoto);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at');
                if ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrandAssets::route('/'),
        ];
    }
}
