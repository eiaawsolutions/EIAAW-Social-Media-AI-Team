<?php

namespace App\Filament\Agency\Resources\Brands;

use App\Filament\Agency\Resources\Brands\Pages\ManageBrands;
use App\Models\Brand;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        return parent::getEloquentQuery()
            ->whereNull('archived_at')
            ->when($workspaceId, fn (Builder $q) => $q->where('workspace_id', $workspaceId));
    }
}
