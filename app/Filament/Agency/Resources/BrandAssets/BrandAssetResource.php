<?php

namespace App\Filament\Agency\Resources\BrandAssets;

use App\Filament\Agency\Resources\BrandAssets\Pages\ManageBrandAssets;
use App\Filament\Agency\Resources\Brands;
use App\Models\Brand;
use App\Models\BrandAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

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
    protected static ?int $navigationSort = 3;

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
                Tables\Columns\TextColumn::make('usage_intent')
                    ->label('Usage')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === BrandAsset::INTENT_CUSTOMISED ? 'Customised post' : 'General')
                    ->color(fn (?string $state) => $state === BrandAsset::INTENT_CUSTOMISED ? 'warning' : 'gray')
                    ->icon(fn (?string $state) => $state === BrandAsset::INTENT_CUSTOMISED ? 'heroicon-m-calendar-days' : 'heroicon-m-photo')
                    ->description(fn (BrandAsset $r) => $r->isCustomised() && $r->scheduled_post_for
                        ? $r->scheduled_post_for->setTimezone($r->brand?->timezone ?: 'UTC')->format('M j, g:i A')
                        . ' · ' . implode(', ', (array) ($r->scheduled_platforms ?? []))
                        : null),
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
                Tables\Filters\SelectFilter::make('usage_intent')
                    ->label('Usage')
                    ->options([
                        BrandAsset::INTENT_GENERAL => 'General (agent pool)',
                        BrandAsset::INTENT_CUSTOMISED => 'Customised post',
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
                    ->modalDescription('Re-runs vision tagging to regenerate the description, tags, and embedding (~3s).')
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
                \Filament\Actions\DeleteAction::make()
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('Delete this asset permanently?')
                    ->modalDescription('Removes the row AND the underlying file from storage. Drafts that already published using this asset keep working — the platform CDN has its own copy. Use Archive instead if you want it hidden but recoverable.')
                    ->before(function (BrandAsset $record): void {
                        self::deleteAssetFile($record);
                    }),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->label('Delete selected')
                        ->modalHeading('Delete selected assets permanently?')
                        ->modalDescription('Removes each row AND its file from storage. This cannot be undone.')
                        ->before(function (\Illuminate\Support\Collection $records): void {
                            foreach ($records as $record) {
                                self::deleteAssetFile($record);
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('No assets yet')
            ->emptyStateDescription(fn () => self::workspaceHasBrand()
                ? 'Click "Upload assets" to add brand-approved images and videos. The Designer + Video agents will pick from these before falling back to AI generation.'
                : 'Create a brand first — assets attach to a brand. Then upload brand-approved images and videos for the Designer + Video agents to pick from.')
            ->emptyStateActions(self::workspaceHasBrand() ? [] : [
                \Filament\Actions\Action::make('createBrand')
                    ->label('Create a brand')
                    ->icon('heroicon-o-plus')
                    ->url(Brands\BrandResource::getUrl('index', panel: 'agency')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPhoto);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        // Tenant isolation: a user with no resolvable workspace sees nothing
        // (prevents cross-tenant IDOR). NO super-admin bypass (removed
        // 2026-06-02): the Agency panel is hard own-workspace for EVERYONE,
        // including HQ, which administers other tenants from /admin. See
        // BrandResource for the full rationale.
        if (! $workspaceId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at')->where('workspace_id', $workspaceId);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrandAssets::route('/'),
        ];
    }

    /**
     * Does the current operator's workspace have at least one non-archived
     * brand? Drives the empty-state copy + action so a brand-less workspace is
     * pointed at the Brands page instead of an upload form it can never submit
     * (the upload modal's brand picker is required + empty when no brands exist).
     *
     * Deliberately workspace-scoped with NO super-admin bypass: it must agree
     * with the upload modal's brand picker, which is strictly scoped to the
     * current workspace (see ManageBrandAssets::brandOptions). A super admin on
     * a workspace with zero brands still gets the "create a brand" signpost,
     * because that's the only thing the upload form could offer them here.
     */
    protected static function workspaceHasBrand(): bool
    {
        $user = auth()->user();

        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        if (! $workspaceId) {
            return false;
        }

        return Brand::where('workspace_id', $workspaceId)
            ->whereNull('archived_at')
            ->exists();
    }

    /**
     * Best-effort: remove the underlying file from storage before the row is
     * deleted. We swallow failures (logged) so a missing/already-gone file
     * doesn't block the operator from removing the row.
     */
    protected static function deleteAssetFile(BrandAsset $asset): void
    {
        if (! $asset->storage_disk || ! $asset->storage_path) {
            return;
        }
        try {
            Storage::disk($asset->storage_disk)->delete($asset->storage_path);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('brand-asset delete: storage delete failed', [
                'asset_id' => $asset->id,
                'disk' => $asset->storage_disk,
                'path' => $asset->storage_path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
