<?php

namespace App\Filament\Resources\LegalRules;

use App\Filament\Resources\LegalRules\Pages\ManageLegalRules;
use App\Models\ComplianceLegalRule;
use App\Support\Compliance\IndustryCatalog;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * HQ-only review console for the curated legal / advertising-standards rulebook
 * (compliance_legal_rules) that grounds the legal-compliance check + the
 * Strategist/Writer shift-left prompts.
 *
 * WHY THIS EXISTS: the rulebook was seed-only — editing a rule (e.g. disabling a
 * false-positive [block] rule, or correcting a directive) required a code change
 * + deploy. This gives a legal reviewer an in-app surface to read, enable/disable,
 * and edit rules. New rows can still be added here, but the SEEDER remains the
 * authoritative source of the baseline set (see ComplianceLegalRuleSeeder).
 *
 * ACCESS: super-admin only — two layers, mirroring ClientBrandResource:
 *   1. Panel boundary — only the /admin panel discovers this resource, and that
 *      panel is gated by User::canAccessPanel('admin') => is_super_admin.
 *   2. Resource boundary — canAccess()/canViewAny() re-check is_super_admin so a
 *      future panel-config slip can't expose it.
 *
 * Lives under App\Filament\Resources (NOT Agency\Resources), so the
 * TenantIsolationGuardTest (which scans only the Agency namespace) does not apply
 * — these rules are global, not workspace-scoped, which is correct.
 *
 * Every create/edit/toggle/delete busts the LegalRulesProvider cache via the
 * ComplianceLegalRule model's saved/deleted observer (see the model), so an edit
 * takes effect on the next agent run rather than waiting out the 60s TTL.
 */
class LegalRuleResource extends Resource
{
    protected static ?string $model = ComplianceLegalRule::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;
    protected static ?string $navigationLabel = 'Legal rules';
    protected static ?string $modelLabel = 'legal rule';
    protected static ?string $pluralModelLabel = 'Legal rules';
    protected static \UnitEnum|string|null $navigationGroup = 'Compliance';
    protected static ?int $navigationSort = 1;
    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    /** Industry options for the form: the catalog plus the '*' (all) wildcard. */
    private static function industryOptions(): array
    {
        return ['*' => 'All industries (*)'] + IndustryCatalog::industries();
    }

    /** Jurisdiction options: the supported codes plus the '*' (global) wildcard. */
    private static function jurisdictionOptions(): array
    {
        return [
            '*' => 'Global (*)',
            'MY' => 'Malaysia (MY)',
            'SG' => 'Singapore (SG)',
            'ID' => 'Indonesia (ID)',
            'TH' => 'Thailand (TH)',
            'PH' => 'Philippines (PH)',
            'VN' => 'Vietnam (VN)',
            'BN' => 'Brunei (BN)',
            'AU' => 'Australia (AU)',
            'GB' => 'United Kingdom (GB)',
            'US' => 'United States (US)',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Select::make('industry')
                ->options(self::industryOptions())
                ->required()
                ->searchable()
                ->helperText('"All industries" applies the rule to every brand regardless of vertical.'),
            Forms\Components\Select::make('jurisdiction')
                ->options(self::jurisdictionOptions())
                ->required()
                ->searchable()
                ->helperText('"Global" applies the rule in every jurisdiction.'),
            Forms\Components\TextInput::make('rule_code')
                ->required()
                ->maxLength(40)
                ->helperText('Stable identifier cited back in violation reports, e.g. MY-FIN-001. Unique per industry+jurisdiction.'),
            Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('directive')
                ->required()
                ->rows(3)
                ->helperText('The rule, phrased as an instruction the planner/writer obeys and the judge enforces.'),
            Forms\Components\Select::make('severity')
                ->options([
                    'block' => 'Block (a violation HOLDS the draft)',
                    'advisory' => 'Advisory (surfaced, does not block)',
                ])
                ->default('block')
                ->required(),
            Forms\Components\TextInput::make('source')
                ->maxLength(255)
                ->helperText('Citation — the act / regulator / standard this rule derives from (auditability).'),
            Forms\Components\Toggle::make('disabled')
                ->helperText('Disable a false-positive rule without deleting it. Disabled rules are never applied.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('rule_code')
            ->columns([
                Tables\Columns\TextColumn::make('rule_code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('industry')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => $state === '*' ? 'All' : IndustryCatalog::label($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('jurisdiction')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->wrap()
                    ->searchable()
                    ->limit(60),
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state) => $state === 'block' ? 'danger' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable()
                    ->limit(40),
                Tables\Columns\ToggleColumn::make('disabled')
                    ->label('Disabled')
                    ->tooltip('Toggle to disable/enable this rule (takes effect immediately).'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime('d M, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('industry')->options(self::industryOptions()),
                Tables\Filters\SelectFilter::make('jurisdiction')->options(self::jurisdictionOptions()),
                Tables\Filters\SelectFilter::make('severity')->options([
                    'block' => 'Block',
                    'advisory' => 'Advisory',
                ]),
                Tables\Filters\TernaryFilter::make('disabled')
                    ->label('Disabled')
                    ->placeholder('All rules')
                    ->trueLabel('Disabled only')
                    ->falseLabel('Active only'),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add rule'),
            ])
            ->emptyStateHeading('No legal rules yet')
            ->emptyStateDescription('Run the ComplianceLegalRuleSeeder to load the curated baseline rulebook, or add a rule.')
            ->emptyStateIcon(Heroicon::OutlinedScale);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageLegalRules::route('/'),
        ];
    }
}
