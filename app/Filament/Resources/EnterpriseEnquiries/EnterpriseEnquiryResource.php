<?php

namespace App\Filament\Resources\EnterpriseEnquiries;

use App\Filament\Resources\EnterpriseEnquiries\Pages\ManageEnterpriseEnquiries;
use App\Models\EnterpriseEnquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * HQ-only view of Enterprise "Talk to us" leads captured by the dedicated
 * /enterprise page. Read + triage (contacted / qualified / closed); enquiries
 * are never created here — they arrive via POST /enterprise.
 *
 * Separate from SupportEnquiryResource because Enterprise leads carry the sales-
 * scoping fields (company size, brand count, monthly video volume, budget band)
 * the team uses to shape a bespoke plan, and warrant their own pipeline.
 *
 * Lives in the admin (HQ) panel's resource-discovery path only. The agency
 * (client) panel never discovers this — clients never see other people's leads.
 */
class EnterpriseEnquiryResource extends Resource
{
    protected static ?string $model = EnterpriseEnquiry::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;
    protected static ?string $navigationLabel = 'Enterprise enquiries';
    protected static ?string $modelLabel = 'enterprise enquiry';
    protected static ?string $pluralModelLabel = 'Enterprise enquiries';
    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $new = static::getModel()::where('status', 'new')->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('d M, H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('company')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Contact')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('company_size')
                    ->label('Size')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('brands_needed')
                    ->label('Brands')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('videos_per_month')
                    ->label('Vids/mo')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('budget_band')
                    ->label('Budget')
                    ->placeholder('—')
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('message')
                    ->wrap()
                    ->limit(60)
                    ->tooltip(fn (EnterpriseEnquiry $r) => $r->message),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning',
                        'contacted' => 'info',
                        'qualified' => 'success',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'new' => 'New',
                    'contacted' => 'Contacted',
                    'qualified' => 'Qualified',
                    'closed' => 'Closed',
                ]),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (EnterpriseEnquiry $r) => "Enterprise lead #{$r->id} — {$r->company}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        \Filament\Infolists\Components\TextEntry::make('company')->weight('bold'),
                        \Filament\Infolists\Components\TextEntry::make('name')->label('Contact'),
                        \Filament\Infolists\Components\TextEntry::make('email')->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('phone')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('website')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('company_size')->label('Company size')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('brands_needed')->label('Brands needed')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('videos_per_month')->label('Videos / month')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('budget_band')->label('Budget band')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('message')->columnSpanFull(),
                        \Filament\Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    ]),
                \Filament\Actions\Action::make('reply')
                    ->label('Reply by email')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (EnterpriseEnquiry $r) => 'mailto:' . $r->email . '?subject=' . rawurlencode('Re: EIAAW Social Media Team — Enterprise'))
                    ->openUrlInNewTab(),
                \Filament\Actions\Action::make('markContacted')
                    ->label('Mark contacted')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (EnterpriseEnquiry $r) => $r->status === 'new')
                    ->action(function (EnterpriseEnquiry $r): void {
                        $r->update(['status' => 'contacted', 'handled_at' => now()]);
                        \Filament\Notifications\Notification::make()->title('Marked contacted')->success()->send();
                    }),
                \Filament\Actions\Action::make('markQualified')
                    ->label('Mark qualified')
                    ->icon('heroicon-o-star')
                    ->color('success')
                    ->visible(fn (EnterpriseEnquiry $r) => in_array($r->status, ['new', 'contacted'], true))
                    ->action(function (EnterpriseEnquiry $r): void {
                        $r->update(['status' => 'qualified', 'handled_at' => now()]);
                        \Filament\Notifications\Notification::make()->title('Marked qualified')->success()->send();
                    }),
                \Filament\Actions\Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (EnterpriseEnquiry $r) => $r->status !== 'closed')
                    ->action(function (EnterpriseEnquiry $r): void {
                        $r->update(['status' => 'closed', 'handled_at' => now()]);
                        \Filament\Notifications\Notification::make()->title('Closed')->send();
                    }),
            ])
            ->emptyStateHeading('No Enterprise enquiries yet')
            ->emptyStateDescription('Leads from the "Talk to us" Enterprise page will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEnterpriseEnquiries::route('/'),
        ];
    }
}
