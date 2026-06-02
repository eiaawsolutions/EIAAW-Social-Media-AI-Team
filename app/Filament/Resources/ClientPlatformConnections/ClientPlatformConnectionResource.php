<?php

namespace App\Filament\Resources\ClientPlatformConnections;

use App\Filament\Resources\ClientPlatformConnections\Pages\ManageClientPlatformConnections;
use App\Models\Brand;
use App\Models\PlatformConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * HQ CLIENT PLATFORM CONNECTIONS — cross-tenant administration of every
 * workspace's social connections, relocated from the customer-facing Agency
 * PlatformConnectionResource (incident 2026-06-02; see ClientBrandResource for
 * the full rationale).
 *
 * A PlatformConnection is the single most sensitive tenant object in SMT: it is
 * the OAuth/Metricool routing record for a brand's social account. Cross-tenant
 * visibility of these is therefore restricted to the /admin panel, gated by
 * User::canAccessPanel('admin') => is_super_admin, AND re-checked at the resource
 * boundary below. Customers see ONLY their own workspace's connections, in
 * /agency, where the resource is now hard workspace-scoped with no super-admin
 * bypass.
 *
 * Capability ported here (Amos: "full edit + onboarding actions"):
 *   - "Brand / Workspace" column so HQ always knows whose connection a row is
 *   - routing-space (metricool_blog_id) column
 *   - per-network "Target overrides" modal (writes PlatformConnection.target_overrides,
 *     consumed verbatim by MetricoolPublisher::perNetworkData at publish time)
 *   - a "Hide revoked" toggle (default ON) to surface inert tombstones for audit
 *   - a Brand filter to focus a single tenant
 */
class ClientPlatformConnectionResource extends Resource
{
    protected static ?string $model = PlatformConnection::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;
    protected static ?string $navigationLabel = 'Client platforms';
    protected static ?string $modelLabel = 'Client platform connection';
    protected static ?string $pluralModelLabel = 'Client platforms';
    protected static \UnitEnum|string|null $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

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
                // "Whose account is this?" — always shown here (this panel is
                // cross-tenant by definition). Brand name + owning workspace.
                Tables\Columns\TextColumn::make('brand.name')
                    ->label('Brand / Workspace')
                    ->description(fn (PlatformConnection $r) => $r->brand?->workspace
                        ? 'ws: ' . $r->brand->workspace->name
                        : null)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'brand',
                        fn (Builder $q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhereHas('workspace', fn (Builder $w) => $w->where('name', 'like', "%{$search}%")),
                    ))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('brand.metricool_blog_id')
                    ->label('Routing space')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->limit(16)
                    ->tooltip('The Metricool brand (blogId) this connection routes through.')
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
                        : 'Personal account (no overrides — we route to the connected profile)'),
            ])
            ->filters([
                Tables\Filters\Filter::make('hide_revoked')
                    ->label('Hide revoked connections')
                    ->toggle()
                    ->default(true)
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', 'revoked')),

                // Focus a single tenant. Options labelled "Brand (Workspace)" so
                // same-named brands across tenants stay distinguishable. No HQ
                // default here — this panel shows all clients by design.
                Tables\Filters\SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => self::brandFilterOptions()),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('targetOverrides')
                    ->label('Target overrides')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('gray')
                    ->modalHeading(fn (PlatformConnection $r) => 'Target overrides — ' . ucfirst($r->platform) . ' @' . ($r->display_handle ?: '?'))
                    ->modalDescription('Personal accounts: leave fields blank — we route to the connected profile. Business pages: paste the platform-side numeric ID. These values get sent verbatim on every publish to this connection.')
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
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No client platform connections')
            ->emptyStateDescription('Once a client connects a social account in Metricool and it syncs, it appears here.')
            ->emptyStateIcon(Heroicon::OutlinedLink);
    }

    /**
     * Cross-tenant by design. Eager-loads brand + workspace for the
     * "Brand / Workspace" column. Hides connections whose brand is archived
     * (the brand was retired; its connections are noise). Revoked rows are NOT
     * hidden here — the "Hide revoked" filter (default ON) governs that, so HQ
     * can toggle tombstones back in for audit. The panel-level super-admin gate
     * is what makes seeing every tenant safe.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('brand.workspace')
            ->whereHas('brand', fn (Builder $q) => $q->whereNull('archived_at'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientPlatformConnections::route('/'),
        ];
    }

    /**
     * Every non-archived brand that owns at least one connection, labelled
     * "Brand (Workspace)". Restricted to brands-with-connections so the picker
     * isn't cluttered with brands that would filter the table to empty.
     *
     * @return array<int, string>
     */
    private static function brandFilterOptions(): array
    {
        return Brand::query()
            ->whereNull('archived_at')
            ->whereHas('platformConnections')
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Brand $b) => [
                $b->id => $b->workspace
                    ? sprintf('%s (%s)', $b->name, $b->workspace->name)
                    : $b->name,
            ])
            ->all();
    }

    /**
     * Per-platform fields for the "Target overrides" modal. Map onto
     * MetricoolPublisher::perNetworkData() — what the operator sets here is what
     * gets merged into every publish to this connection.
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
            default => [
                \Filament\Forms\Components\Placeholder::make('no_overrides')
                    ->label('')
                    ->content('This platform routes to the connected account automatically. No overrides needed for personal or business use.'),
            ],
        };
    }

    /**
     * Pre-populate the modal from the row's existing target_overrides JSON.
     *
     * @return array<string, mixed>
     */
    private static function fillFormFromOverrides(PlatformConnection $r): array
    {
        $overrides = is_array($r->target_overrides) ? $r->target_overrides : [];

        return collect($overrides)
            ->map(fn ($v) => is_string($v) && in_array($v, ['true', 'false'], true) ? $v === 'true' : $v)
            ->all();
    }
}
