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
                    ->tooltip(fn (EnterpriseEnquiry $r) => $r->message)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('agreed_price_myr')
                    ->label('Agreed')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state) => $state ? 'RM ' . number_format($state) . '/mo' : null)
                    ->alignEnd()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('invoice_status')
                    ->label('Invoice')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        'paid' => 'success',
                        'sent' => 'warning',
                        'void' => 'gray',
                        'draft' => 'info',
                        default => 'gray',
                    }),
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
                \Filament\Actions\Action::make('provisionAndInvoice')
                    ->label('Provision & invoice')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    // Only before a workspace exists for this deal — keeps the
                    // action idempotent at the UI level too (the service is
                    // idempotent regardless).
                    ->visible(fn (EnterpriseEnquiry $r) => $r->provisioned_workspace_id === null)
                    ->modalHeading(fn (EnterpriseEnquiry $r) => "Provision Enterprise — {$r->company}")
                    ->modalDescription('Creates a bespoke enterprise workspace (inactive) and emails the customer a one-off Stripe invoice. The workspace activates automatically when the invoice is paid. No recurring subscription is created.')
                    ->modalSubmitActionLabel('Provision + send invoice')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('brands')
                            ->label('Brands')
                            ->numeric()->required()->minValue(1)->default(10),
                        \Filament\Forms\Components\TextInput::make('image_posts')
                            ->label('AI image posts / month')
                            ->numeric()->required()->minValue(0)->default(300),
                        \Filament\Forms\Components\TextInput::make('video_posts')
                            ->label('AI 15-sec video posts / month')
                            ->numeric()->required()->minValue(0)->default(48),
                        \Filament\Forms\Components\TextInput::make('price_myr')
                            ->label('Agreed price (RM / month)')
                            ->numeric()->required()->minValue(1)
                            ->helperText('Bespoke monthly figure. A one-off Stripe invoice for this amount is sent now; re-invoice each term.'),
                    ])
                    ->action(function (EnterpriseEnquiry $r, array $data): void {
                        try {
                            app(\App\Services\Billing\EnterpriseProvisioner::class)->provisionAndInvoice($r, [
                                'brands' => (int) $data['brands'],
                                'image_posts' => (int) $data['image_posts'],
                                'video_posts' => (int) $data['video_posts'],
                                'price_myr' => (int) $data['price_myr'],
                            ]);
                            \Filament\Notifications\Notification::make()
                                ->title('Enterprise provisioned + invoice sent')
                                ->body('Workspace created (inactive). It activates when the customer pays the Stripe invoice.')
                                ->success()->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Provisioning failed')
                                ->body($e->getMessage())
                                ->danger()->persistent()->send();
                        }
                    }),
                \Filament\Actions\Action::make('openInvoice')
                    ->label('Open invoice')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->color('info')
                    ->visible(fn (EnterpriseEnquiry $r) => ! empty($r->stripe_invoice_url))
                    ->url(fn (EnterpriseEnquiry $r) => $r->stripe_invoice_url)
                    ->openUrlInNewTab(),
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
