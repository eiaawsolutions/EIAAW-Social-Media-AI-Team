<?php

namespace App\Filament\Resources\ClientBrands;

use App\Filament\Resources\ClientBrands\Pages\ManageClientBrands;
use App\Models\Brand;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * HQ CLIENT BRANDS — the cross-tenant administration surface that used to be
 * the `is_super_admin` bypass inside the customer-facing Agency BrandResource.
 *
 * WHY THIS EXISTS (incident 2026-06-02):
 * HQ staff previously saw EVERY workspace's brands inside /agency/brands because
 * BrandResource::getEloquentQuery() returned the unscoped query for super-admins.
 * That was isolation-correct (only super-admins, never customers) but it READ as
 * a breach: the Agency panel — the surface a client also uses — showed a client's
 * brand next to EIAAW's own, with no workspace label to tell them apart.
 *
 * The fix (Amos's decision): /agency is now PURELY own-workspace for everyone,
 * including HQ. ALL cross-tenant client administration lives here, under /admin —
 * a panel only super-admins can reach (User::canAccessPanel('admin') gate). Every
 * row is labelled with its owning Workspace so two same-named brands across
 * tenants stay distinguishable, which is the clarity the old view lacked.
 *
 * Access is enforced at TWO layers (defense in depth):
 *   1. Panel boundary — User::canAccessPanel('admin') => is_super_admin. A
 *      non-super-admin can't load any /admin route at all.
 *   2. Resource boundary — canViewAny()/canAccess() below re-check is_super_admin
 *      so a future panel-config change can't silently expose this resource.
 *
 * This resource is intentionally NOT under App\Filament\Agency\Resources, so the
 * TenantIsolationGuardTest (which scans only the Agency namespace) does not — and
 * must not — apply its workspace-scoping rule here. Cross-tenant visibility is
 * the entire point, and it is safe precisely because the panel gate restricts it
 * to HQ.
 */
class ClientBrandResource extends Resource
{
    protected static ?string $model = Brand::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;
    protected static ?string $navigationLabel = 'Client brands';
    protected static ?string $modelLabel = 'Client brand';
    protected static ?string $pluralModelLabel = 'Client brands';
    protected static \UnitEnum|string|null $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'name';

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
        return $schema
            ->components([
                // The owning workspace is shown read-only — HQ administers a
                // brand IN PLACE; it never re-parents a brand to a different
                // tenant from here (that would be a cross-tenant data move and
                // must go through an explicit, audited path, not a form select).
                Forms\Components\Placeholder::make('workspace_label')
                    ->label('Workspace (owner)')
                    ->content(fn (?Brand $record) => $record?->workspace
                        ? "{$record->workspace->name}  ·  ws #{$record->workspace_id}"
                        : '—'),
                Forms\Components\TextInput::make('name')
                    ->label('Brand name')
                    ->required()
                    ->maxLength(120),
                Forms\Components\TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->maxLength(120)
                    ->alphaDash()
                    ->helperText('Used in URLs. Lowercase, hyphens only.'),
                Forms\Components\TextInput::make('website_url')
                    ->label('Website URL')
                    ->url()
                    ->placeholder('https://example.com'),
                Forms\Components\Select::make('industry')
                    ->label('Industry')
                    ->options(\App\Support\Compliance\IndustryCatalog::industries())
                    ->searchable()
                    ->required()
                    ->helperText('Drives the legal compliance rules (advertising & industry laws for the brand\'s country) applied to every post.'),
                Forms\Components\Select::make('locale')
                    ->options([
                        'en' => 'English',
                        'ms' => 'Bahasa Malaysia',
                        'zh' => 'Chinese',
                        'id' => 'Bahasa Indonesia',
                    ])
                    ->default('en')
                    ->required(),
                Forms\Components\Select::make('timezone')
                    ->options(collect(\DateTimeZone::listIdentifiers(\DateTimeZone::ASIA))
                        ->mapWithKeys(fn ($tz) => [$tz => $tz])
                        ->prepend('UTC', 'UTC')
                        ->all())
                    ->default('Asia/Kuala_Lumpur')
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('metricool_blog_id')
                    ->label('Metricool blogId (routing space)')
                    ->numeric()
                    ->helperText('The Metricool brand this SMT brand publishes through. Prefer setting this via the Platform onboarding console / brand:set-metricool-blog so connection state is detected too; editable here for support corrections.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                // The column whose absence caused the 2026-06-02 confusion:
                // every row carries its owning workspace + owner email so HQ can
                // never again mistake one tenant's brand for another's.
                Tables\Columns\TextColumn::make('workspace.name')
                    ->label('Workspace (owner)')
                    ->description(fn (Brand $r) => $r->workspace?->owner?->email)
                    ->badge()
                    ->color('primary')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'workspace',
                        fn (Builder $w) => $w->where('name', 'like', "%{$search}%")
                            ->orWhereHas('owner', fn (Builder $o) => $o->where('email', 'like', "%{$search}%")),
                    ))
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('slug')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('website_url')
                    ->url(fn ($record) => $record->website_url)
                    ->openUrlInNewTab()
                    ->limit(36)
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('industry')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('metricool_blog_id')
                    ->label('Routing space')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                // Filter by workspace — the natural cross-tenant lens for HQ.
                Tables\Filters\SelectFilter::make('workspace_id')
                    ->label('Workspace')
                    ->relationship('workspace', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Archived only')
                    ->falseLabel('Active only')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('archived_at'),
                        false: fn (Builder $q) => $q->whereNull('archived_at'),
                        blank: fn (Builder $q) => $q->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Brand $record) => $record->archived_at === null)
                    ->modalHeading(fn (Brand $record) => "Archive {$record->name}")
                    ->modalDescription('This client brand and all its content stay safe and can be restored. It is removed from the active list and frees the workspace\'s brand slot.')
                    ->successNotificationTitle('Brand archived')
                    ->action(fn (Brand $record) => $record->update(['archived_at' => now()])),
                \Filament\Actions\Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Brand $record) => $record->archived_at !== null)
                    ->modalHeading(fn (Brand $record) => "Restore {$record->name}")
                    ->successNotificationTitle('Brand restored')
                    ->action(fn (Brand $record) => $record->update(['archived_at' => null])),
            ])
            ->defaultSort('workspace_id')
            ->emptyStateHeading('No client brands yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientBrands::route('/'),
        ];
    }

    /**
     * Cross-tenant by design — eager-load the workspace + its owner for the
     * "Workspace (owner)" column so the all-tenants view doesn't N+1. NO
     * workspace_id constraint here: the panel-level super-admin gate
     * (User::canAccessPanel('admin')) is what makes seeing every tenant safe.
     * Includes archived brands so HQ can restore them; the table's default
     * "Active only" filter hides them unless HQ opts in.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('workspace.owner');
    }
}
