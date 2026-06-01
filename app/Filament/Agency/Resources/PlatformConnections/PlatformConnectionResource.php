<?php

namespace App\Filament\Agency\Resources\PlatformConnections;

use App\Filament\Agency\Resources\PlatformConnections\Pages\ManagePlatformConnections;
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
 * Platform Connections — read-only-ish list of social accounts the customer
 * has connected via Metricool. The actual connection flow happens through a
 * Metricool connect-link in the "Platform setup" wizard (MetricoolSetup);
 * Metricool has done App Review with each platform and we read the resulting
 * connection state per brand from /admin/profile.
 *
 * The table page exposes:
 *   - One row per connected network (per brand)
 *   - "Refresh from Metricool" header action (re-reads /admin/profile via
 *     MetricoolConnectionService::sync)
 *   - "Connect a platform" header action → points at the connect-link wizard
 *   - Target overrides per connection (consumed by MetricoolPublisher at
 *     publish time) — editing one marks nothing revoked; preserves the
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
    protected static ?int $navigationSort = 4;

    /**
     * No editable form for v1 — Metricool is the source of truth for which
     * accounts are connected (read from /admin/profile). Stub schema satisfies
     * Filament's resource contract; the page uses `ManageRecords` (no per-record
     * edit modal). The one editable thing — target overrides — lives in its own
     * record action below.
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
                // HQ-only "whose account is this?" column. Customers only ever
                // see their own single workspace's brands (getEloquentQuery
                // scopes them), so the brand+workspace name is noise for them —
                // but for a super admin the table stacks EVERY tenant's
                // connections behind nothing but a cryptic numeric routing
                // space, which is exactly the confusion this column resolves.
                // Shows the brand name with the owning workspace as the
                // description line. Searchable so HQ can jump to a tenant fast.
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
                    ->placeholder('—')
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin),
                Tables\Columns\TextColumn::make('brand.metricool_blog_id')
                    ->label('Routing space')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->limit(16)
                    ->tooltip('The secure space this connection is routed through. Set once per brand by EIAAW during setup.')
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
                // Super-admin-only opt-in to surface revoked tombstones for
                // support/audit. Defaults to ON (= hide revoked) so HQ gets the
                // same clean view a customer does; toggling it OFF reveals the
                // revoked rows.
                //
                // Mechanics (Filament v5): a filter's query callback runs ONLY
                // when the toggle is active (InteractsWithTableQuery::apply
                // short-circuits when isActive is false), so the filter is framed
                // as "hide" (active = hide) rather than "show" (active = show).
                // A *hidden* filter applies nothing at all, which is why customer
                // hiding lives in getEloquentQuery() instead of here — this
                // control is only ever shown to, and only ever affects, super
                // admins.
                Tables\Filters\Filter::make('hide_revoked')
                    ->label('Hide revoked connections')
                    ->toggle()
                    ->default(true)
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', 'revoked')),

                // HQ-only brand filter. Lets a super admin narrow the
                // all-workspaces view to a single tenant's brand — pairs with
                // the Brand/Workspace column above. Options are every
                // non-archived brand that actually has connections, labelled
                // "Brand (Workspace)" so two same-named brands across tenants
                // stay distinguishable. Hidden for customers: their view is
                // already scoped to one workspace, and they typically have a
                // single brand, so a brand picker would be empty ceremony.
                Tables\Filters\SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->searchable()
                    ->options(fn () => self::brandFilterOptions())
                    ->visible(fn () => (bool) auth()->user()?->is_super_admin),
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
            ->emptyStateHeading('No platforms connected yet')
            ->emptyStateDescription('Connect your social accounts via the secure link in "Platform setup", then click "Refresh connections" above. Once a platform is authorised, your connection appears here automatically.')
            ->emptyStateIcon(Heroicon::OutlinedLink);
    }

    /**
     * Constrain to brands the current user's workspace owns. Same pattern as
     * BrandResource::getEloquentQuery — single source of truth across list,
     * record-resolution, summary, and modal queries.
     *
     * 'revoked' connections are hidden by default for EVERYONE. Those rows are
     * inert tombstones (legacy Blotato-era rows the Metricool sync revoked rather
     * than deleted, to preserve the ScheduledPost audit chain — see
     * MetricoolConnectionService::sync). They never re-activate, so they're noise
     * in the default view. We keep 'expired'/'reauth_required' visible — those
     * are states the customer must act on.
     *
     * Two different mechanisms enforce the hide, because they answer different
     * questions:
     *   - Non-super-admins (customers): hidden HERE, unconditionally. They never
     *     see the revoked filter (it's super-admin-only), and a hidden Filament
     *     filter applies no query at all — so the exclusion must live in the base
     *     query for them.
     *   - Super admins (HQ): hidden by the "Hide revoked connections" table
     *     filter, which defaults to ON. This gives HQ the same clean default view
     *     a customer gets, while letting them toggle it OFF to surface tombstones
     *     for support/audit. The exclusion is deliberately NOT hardcoded here for
     *     them, because a base-query exclusion would strip revoked rows before the
     *     filter could ever add them back.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        // Eager-load brand + its workspace: the Routing-space column reads
        // brand.metricool_blog_id and (for super admins) the Brand/Workspace
        // column reads brand.workspace.name. Without this, the all-workspaces
        // HQ view N+1s one brand + one workspace query per row.
        $query = parent::getEloquentQuery()->with('brand.workspace');

        // Tenant isolation: super admin bypasses workspace scoping (support);
        // anyone else without a resolvable workspace sees nothing (prevents
        // cross-tenant IDOR — a platform connection holds OAuth tokens, so
        // leakage is high-impact). Super admins control revoked visibility via
        // the table filter, so no status constraint is applied to them here.
        if ($user?->is_super_admin) {
            return $query
                ->whereHas('brand', fn (Builder $q) => $q->whereNull('archived_at'));
        }

        if (! $workspaceId) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status', '!=', 'revoked')
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at')->where('workspace_id', $workspaceId);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlatformConnections::route('/'),
        ];
    }

    /**
     * Options for the super-admin "Brand" filter: every non-archived brand that
     * actually owns at least one platform connection, labelled
     * "Brand (Workspace)" so two same-named brands in different tenants stay
     * distinguishable. Restricted to brands-with-connections so the picker
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
     * Per-platform form fields for the "Target overrides" modal. These map onto
     * MetricoolPublisher::perNetworkData() (the connection's target_overrides →
     * Metricool's `<network>Data` block), so what the operator sets here is
     * exactly what gets merged into every publish to this connection.
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
