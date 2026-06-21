<?php

namespace App\Filament\Agency\Resources\GrowthGoals;

use App\Filament\Agency\Resources\GrowthGoals\Pages\ManageGrowthGoals;
use App\Models\BrandGrowthGoal;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operator-set growth goals per brand (e.g. "grow Instagram followers to 5,000
 * by Sep"). The GrowthStrategistAgent reads active goals and biases its guidance
 * toward them; the /agency/performance dashboard shows progress.
 *
 * Own-workspace scoped for EVERYONE (incl. HQ) — same discipline as
 * PlatformConnectionResource::getEloquentQuery (the agency panel is hard
 * own-workspace; cross-tenant admin lives in /admin).
 */
class GrowthGoalResource extends Resource
{
    protected static ?string $model = BrandGrowthGoal::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;
    protected static ?string $navigationLabel = 'Growth goals';
    protected static ?string $modelLabel = 'Growth goal';
    protected static ?string $pluralModelLabel = 'Growth goals';
    protected static ?int $navigationSort = 5;

    private const METRIC_LABELS = [
        'followers' => 'Followers',
        'reach' => 'Reach',
        'engagement_rate' => 'Engagement rate',
        'link_clicks' => 'Link clicks',
        'profile_visits' => 'Profile visits',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('brand_id')
                ->label('Brand')
                ->options(fn () => self::workspaceBrands())
                ->required()
                ->native(false),
            Forms\Components\Select::make('target_metric')
                ->label('Metric to grow')
                ->options(self::METRIC_LABELS)
                ->required()
                ->native(false),
            Forms\Components\Select::make('platform')
                ->label('Platform (optional)')
                ->options([
                    'instagram' => 'Instagram',
                    'facebook' => 'Facebook',
                    'linkedin' => 'LinkedIn',
                    'tiktok' => 'TikTok',
                    'youtube' => 'YouTube',
                    'threads' => 'Threads',
                    'x' => 'X (Twitter)',
                    'pinterest' => 'Pinterest',
                ])
                ->placeholder('Account-wide (all platforms)')
                ->native(false)
                ->helperText('Leave blank to target the metric across all platforms.'),
            Forms\Components\TextInput::make('target_value')
                ->label('Target value')
                ->numeric()
                ->minValue(1)
                ->required()
                ->helperText('The number you want to reach by the end date (e.g. 5000 followers).'),
            Forms\Components\DatePicker::make('window_starts_on')
                ->label('Start')
                ->default(now())
                ->required(),
            Forms\Components\DatePicker::make('window_ends_on')
                ->label('Target by')
                ->default(now()->addDays(90))
                ->required()
                ->after('window_starts_on'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand.name')->label('Brand')->sortable(),
                Tables\Columns\TextColumn::make('target_metric')
                    ->label('Metric')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::METRIC_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('platform')
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : 'Account-wide')
                    ->color(fn (?string $state) => $state ? 'primary' : 'gray'),
                Tables\Columns\TextColumn::make('target_value')->label('Target')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('window_ends_on')->label('By')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'achieved' => 'primary',
                        'missed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (BrandGrowthGoal $r) => $r->status === 'active')
                    ->requiresConfirmation()
                    ->action(fn (BrandGrowthGoal $r) => $r->update(['status' => 'archived'])),
            ])
            ->emptyStateHeading('No growth goals yet')
            ->emptyStateDescription('Set a target — followers, reach, link clicks — and the Growth Strategist will bias your content plan and CTAs toward reaching it.')
            ->emptyStateIcon(Heroicon::OutlinedFlag);
    }

    /**
     * Own-workspace scoping for EVERYONE (no super-admin bypass). Mirrors
     * PlatformConnectionResource::getEloquentQuery — a growth goal belongs to a
     * brand, scoped to the user's workspace.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        $query = parent::getEloquentQuery()->with('brand');

        if (! $workspaceId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('workspace_id', $workspaceId);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGrowthGoals::route('/'),
        ];
    }

    /**
     * Brands the current workspace owns (for the create/edit picker). Keeps the
     * goal's brand selection inside the tenant boundary.
     *
     * @return array<int,string>
     */
    public static function workspaceBrands(): array
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');
        if (! $workspaceId) {
            return [];
        }

        return \App\Models\Brand::query()
            ->whereNull('archived_at')
            ->where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
