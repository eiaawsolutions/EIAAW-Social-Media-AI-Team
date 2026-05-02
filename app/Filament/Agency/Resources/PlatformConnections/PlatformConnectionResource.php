<?php

namespace App\Filament\Agency\Resources\PlatformConnections;

use App\Filament\Agency\Resources\PlatformConnections\Pages\ManagePlatformConnections;
use App\Models\PlatformConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform Connections — read-only-ish list of social accounts the customer
 * has connected via Blotato. The actual connection flow happens in Blotato's
 * web UI (Blotato has done App Review with Meta/LinkedIn/TikTok/X/YouTube/etc;
 * we ride on top of their approved permissions for v1).
 *
 * The table page exposes:
 *   - One row per connected account (per brand)
 *   - "Sync from Blotato" header action (calls /v2/users/me/accounts)
 *   - "Open Blotato to add a platform" header action (deep-link)
 *   - Disconnect (marks status=revoked) — does NOT remove the row, preserves
 *     ScheduledPost audit chain
 *
 * Stage-04 of SetupReadiness (`platform_connected`) flips to done = true once
 * at least one PlatformConnection exists for the focused brand with status=active.
 */
class PlatformConnectionResource extends Resource
{
    protected static ?string $model = PlatformConnection::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;
    protected static ?string $navigationLabel = 'Platforms';
    protected static ?string $modelLabel = 'Platform connection';
    protected static ?string $pluralModelLabel = 'Platforms';
    protected static ?int $navigationSort = 2;

    /**
     * No editable form for v1 — Blotato is the source of truth for connections.
     * Stub schema satisfies Filament's resource contract; the page uses
     * `ManageRecords` (no per-record edit modal).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('platform')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'instagram' => 'pink',
                        'facebook' => 'info',
                        'linkedin' => 'primary',
                        'tiktok' => 'gray',
                        'threads' => 'gray',
                        'x' => 'gray',
                        'youtube' => 'danger',
                        'pinterest' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'x' => 'X (Twitter)',
                        default => ucfirst($state),
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('display_handle')
                    ->label('Handle')
                    ->fontFamily('mono')
                    ->prefix('@')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('blotato_account_id')
                    ->label('Blotato ID')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->limit(16)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'expired', 'reauth_required' => 'warning',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last synced')
                    ->since()
                    ->color('gray'),
            ])
            ->emptyStateHeading('No platforms connected yet')
            ->emptyStateDescription('Connect your social accounts inside Blotato, then click "Sync from Blotato" above. Blotato has done the OAuth + app review with each platform; we read the resulting connections.')
            ->emptyStateIcon(Heroicon::OutlinedLink);
    }

    /**
     * Constrain to brands the current user's workspace owns. Same pattern as
     * BrandResource::getEloquentQuery — single source of truth across list,
     * record-resolution, summary, and modal queries.
     */
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
            'index' => ManagePlatformConnections::route('/'),
        ];
    }
}
