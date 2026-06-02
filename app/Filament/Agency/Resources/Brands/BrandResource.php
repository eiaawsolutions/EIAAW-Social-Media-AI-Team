<?php

namespace App\Filament\Agency\Resources\Brands;

use App\Filament\Agency\Resources\Brands\Pages\ManageBrands;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Brand name')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, ?Brand $record) {
                        if (! $record && $state) {
                            $set('slug', \Illuminate\Support\Str::slug($state));
                        }
                    }),
                Forms\Components\TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->maxLength(120)
                    ->alphaDash()
                    ->helperText('Used in URLs. Lowercase, hyphens only.'),
                Forms\Components\TextInput::make('website_url')
                    ->label('Website URL')
                    ->url()
                    ->placeholder('https://example.com')
                    ->helperText('We scrape this during onboarding to synthesise your brand voice.'),
                Forms\Components\TextInput::make('industry')
                    ->maxLength(80)
                    ->placeholder('e.g. SaaS, Healthcare, F&B'),
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
                Forms\Components\Hidden::make('workspace_id')
                    ->default(fn () => auth()->user()->current_workspace_id
                        ?? auth()->user()->ownedWorkspaces()->value('id')),
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
                Tables\Columns\TextColumn::make('slug')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('website_url')
                    ->url(fn ($record) => $record->website_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('industry')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->color('gray'),
            ])
            ->recordActions([
                EditAction::make(),
                // Archive, not delete. A brand owns drafts, scheduled posts,
                // metrics and competitor ads, and is referenced by the
                // append-only audit_log. A hard DELETE would cascade-destroy
                // all client content AND trip the audit_log append-only
                // trigger (SET NULL on audit_log.brand_id is an UPDATE the
                // trigger forbids), aborting the whole transaction. Archiving
                // sets archived_at — the brand drops out of getEloquentQuery()
                // (whereNull('archived_at')) and frees its plan slot via
                // activeBrandsCount(), while every record is preserved and the
                // action is fully reversible.
                Action::make('archive')
                    ->label('Archive')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Brand $record) => "Archive {$record->name}")
                    ->modalDescription('This brand and all its content stay safe and can be restored later. It is removed from your active list and frees up a brand slot.')
                    ->modalSubmitActionLabel('Archive')
                    ->successNotificationTitle('Brand archived')
                    ->action(fn (Brand $record) => $record->update(['archived_at' => now()])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('archive')
                        ->label('Archive selected')
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Archive selected brands')
                        ->modalDescription('The selected brands and all their content stay safe and can be restored later. They are removed from your active list and free up brand slots.')
                        ->modalSubmitActionLabel('Archive')
                        ->action(function (Collection $records): void {
                            $now = now();
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->archived_at === null) {
                                    $record->update(['archived_at' => $now]);
                                    $count++;
                                }
                            }
                            Notification::make()
                                ->title($count === 1 ? '1 brand archived' : "{$count} brands archived")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrands::route('/'),
        ];
    }

    /**
     * Constrain every query (list, summary, single-record lookup, edit form
     * record resolution) to the current user's workspace. Filament calls this
     * to construct the base table query, the summary aggregate query, and
     * record-resolution queries for actions — putting the workspace scope
     * here guarantees the constraint is honored everywhere, not just in
     * modifyQueryUsing on the table.
     *
     * Previously: a workspace_id filter inside modifyQueryUsing leaked through
     * to the summary path, where Filament's HasFilters ran where(closure) on
     * a builder whose underlying query had been mutated in a way that left
     * $this->model null — Eloquent then threw "Call to a member function
     * newQueryWithoutRelationships() on null" at Builder.php:325.
     *
     * NO super-admin bypass (removed 2026-06-02). The Agency panel is the
     * surface a CUSTOMER uses, so it is now hard-scoped to the current user's
     * own workspace for EVERYONE — including EIAAW HQ. HQ administers other
     * tenants' brands from the dedicated /admin panel (ClientBrandResource),
     * never from here. The previous `if ($user?->is_super_admin) return $query;`
     * was isolation-correct but made HQ's own Agency account show every client's
     * brand with no workspace label, which read as a cross-tenant leak. The
     * TenantIsolationGuardTest now FAILS the build if a super-admin bypass is
     * ever reintroduced into any Agency resource.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        $query = parent::getEloquentQuery()->whereNull('archived_at');

        // Tenant isolation: a user with no resolvable workspace sees nothing.
        if (! $workspaceId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('workspace_id', $workspaceId);
    }
}
