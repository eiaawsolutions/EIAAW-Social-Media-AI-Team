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
                Tables\Columns\IconColumn::make('target_overrides')
                    ->label('Overrides')
                    ->boolean()
                    ->trueIcon('heroicon-o-cog-6-tooth')
                    ->falseIcon('heroicon-o-minus')
                    ->getStateUsing(fn (PlatformConnection $r) => is_array($r->target_overrides) && ! empty($r->target_overrides))
                    ->trueColor('primary')
                    ->falseColor('gray')
                    ->tooltip(fn (PlatformConnection $r) => is_array($r->target_overrides) && ! empty($r->target_overrides)
                        ? json_encode($r->target_overrides, JSON_UNESCAPED_SLASHES)
                        : 'Personal account (no overrides — Blotato routes to profile)'),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('targetOverrides')
                    ->label('Target overrides')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->modalHeading(fn (PlatformConnection $r) => 'Target overrides — ' . ucfirst($r->platform) . ' @' . ($r->display_handle ?: '?'))
                    ->modalDescription('Personal accounts: leave fields blank — Blotato routes to your profile. Business pages: paste the platform-side numeric ID. These values get sent verbatim on every publish to this connection.')
                    ->schema(fn (PlatformConnection $r) => self::overrideFieldsFor($r))
                    ->fillForm(fn (PlatformConnection $r) => self::fillFormFromOverrides($r))
                    ->action(function (PlatformConnection $r, array $data): void {
                        $clean = collect($data)
                            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== false)
                            ->all();
                        $r->update(['target_overrides' => $clean ?: null]);
                        \Filament\Notifications\Notification::make()
                            ->title('Saved')
                            ->body($clean
                                ? 'Will use ' . count($clean) . ' override field(s) on next publish.'
                                : 'Cleared. Reverts to personal-profile routing.')
                            ->success()
                            ->send();
                    }),
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

    /**
     * Per-platform form fields for the "Target overrides" modal. Mirrors
     * BlotatoClient::defaultTargetFor() so what the operator sees here is
     * exactly what gets merged at publish time.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private static function overrideFieldsFor(PlatformConnection $r): array
    {
        return match ($r->platform) {
            'linkedin' => [
                \Filament\Forms\Components\TextInput::make('pageId')
                    ->label('LinkedIn Company Page ID (optional)')
                    ->placeholder('Leave blank for personal profile')
                    ->helperText('Only fill this for a LinkedIn Company Page. Get the numeric ID from the page URL: linkedin.com/company/<this-id>. Personal profiles must leave this blank.')
                    ->maxLength(64),
            ],
            'facebook' => [
                \Filament\Forms\Components\TextInput::make('pageId')
                    ->label('Facebook Page ID (optional)')
                    ->placeholder('Leave blank for personal profile')
                    ->helperText('Only fill this for a Facebook Page. Get the numeric Page ID from your page Settings → About. Personal profiles must leave this blank.')
                    ->maxLength(64),
            ],
            'pinterest' => [
                \Filament\Forms\Components\TextInput::make('boardId')
                    ->label('Pinterest Board ID (required)')
                    ->placeholder('e.g. 123456789012345678')
                    ->helperText('Pinterest requires a target board for every pin. Get the board ID from the board URL or Pinterest API. There is no personal-profile fallback.')
                    ->required()
                    ->maxLength(64),
            ],
            'tiktok' => [
                \Filament\Forms\Components\Select::make('privacyLevel')
                    ->label('Privacy level')
                    ->options([
                        'PUBLIC_TO_EVERYONE' => 'Public — visible on the For You page',
                        'MUTUAL_FOLLOW_FRIENDS' => 'Friends only',
                        'FOLLOWER_OF_CREATOR' => 'Followers only',
                        'SELF_ONLY' => 'Only me (default — safest for testing)',
                    ])
                    ->helperText('SELF_ONLY is the safe default and what we use if you leave this unset.'),
                \Filament\Forms\Components\Toggle::make('disabledComments')->label('Disable comments'),
                \Filament\Forms\Components\Toggle::make('disabledDuet')->label('Disable Duet'),
                \Filament\Forms\Components\Toggle::make('disabledStitch')->label('Disable Stitch'),
                \Filament\Forms\Components\Toggle::make('isBrandedContent')->label('Branded content'),
                \Filament\Forms\Components\Toggle::make('isYourBrand')->label('Your brand'),
            ],
            'youtube' => [
                \Filament\Forms\Components\Select::make('privacyStatus')
                    ->label('Privacy status')
                    ->options([
                        'public' => 'Public',
                        'unlisted' => 'Unlisted',
                        'private' => 'Private (default — safest for testing)',
                    ])
                    ->helperText('Defaults to private if unset.'),
                \Filament\Forms\Components\Toggle::make('shouldNotifySubscribers')->label('Notify subscribers'),
                \Filament\Forms\Components\Toggle::make('isMadeForKids')->label('Made for kids'),
            ],
            'threads' => [
                \Filament\Forms\Components\Select::make('replyControl')
                    ->label('Reply control')
                    ->options([
                        'everyone' => 'Everyone (default)',
                        'accounts_you_follow' => 'Accounts you follow',
                        'mentioned_only' => 'Mentioned only',
                    ])
                    ->helperText('Defaults to everyone if unset.'),
            ],
            // Instagram, X, Bluesky: targetType-only — no per-account overrides
            // are required. Show a friendly note instead of empty form.
            default => [
                \Filament\Forms\Components\Placeholder::make('no_overrides')
                    ->label('')
                    ->content('This platform routes to the connected account automatically. No overrides needed for personal or business use.'),
            ],
        };
    }

    /**
     * Pre-populate the modal from the row's existing target_overrides JSON.
     * Casts boolean toggles back to booleans so Filament shows them correctly.
     *
     * @return array<string, mixed>
     */
    private static function fillFormFromOverrides(PlatformConnection $r): array
    {
        $overrides = is_array($r->target_overrides) ? $r->target_overrides : [];
        // Coerce stored booleans (sometimes stringified by the JSON pass).
        return collect($overrides)
            ->map(fn ($v) => is_string($v) && in_array($v, ['true', 'false'], true) ? $v === 'true' : $v)
            ->all();
    }
}
