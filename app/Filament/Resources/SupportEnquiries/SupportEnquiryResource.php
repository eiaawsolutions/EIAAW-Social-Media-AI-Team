<?php

namespace App\Filament\Resources\SupportEnquiries;

use App\Filament\Resources\SupportEnquiries\Pages\ManageSupportEnquiries;
use App\Models\SupportEnquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * HQ-only view of "Talk to us" leads captured by the floating chatbot across
 * the landing page + client/HQ panels. Read + triage (mark contacted / closed);
 * enquiries are never created here — they arrive via POST /api/contact.
 *
 * Lives in the admin (HQ) panel's resource-discovery path. The agency (client)
 * panel does NOT discover this — clients never see other people's leads.
 */
class SupportEnquiryResource extends Resource
{
    protected static ?string $model = SupportEnquiry::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;
    protected static ?string $navigationLabel = 'Enquiries';
    protected static ?string $modelLabel = 'enquiry';
    protected static ?string $pluralModelLabel = 'Enquiries';
    protected static ?int $navigationSort = 5;

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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('company')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => [
                        'chat_gate' => 'Chat gate',
                        'enquiry' => 'Enquiry',
                    ][$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'chat_gate' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('surface')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => [
                        'landing' => 'Landing',
                        'client' => 'Client',
                        'hq' => 'HQ',
                    ][$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'client' => 'success',
                        'hq' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message')
                    ->wrap()
                    ->limit(70)
                    ->tooltip(fn (SupportEnquiry $r) => $r->message),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning',
                        'contacted' => 'info',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'new' => 'New',
                    'contacted' => 'Contacted',
                    'closed' => 'Closed',
                ]),
                Tables\Filters\SelectFilter::make('kind')->label('Source')->options([
                    'enquiry' => 'Enquiry',
                    'chat_gate' => 'Chat gate',
                ]),
                Tables\Filters\SelectFilter::make('surface')->options([
                    'landing' => 'Landing page',
                    'client' => 'Client panel',
                    'hq' => 'HQ panel',
                ]),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (SupportEnquiry $r) => "Enquiry #{$r->id} — {$r->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->infolist([
                        \Filament\Infolists\Components\TextEntry::make('name'),
                        \Filament\Infolists\Components\TextEntry::make('email')->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('phone')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('company')->placeholder('—'),
                        \Filament\Infolists\Components\TextEntry::make('surface')->badge(),
                        \Filament\Infolists\Components\TextEntry::make('message')->columnSpanFull(),
                        \Filament\Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    ]),
                \Filament\Actions\Action::make('reply')
                    ->label('Reply by email')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (SupportEnquiry $r) => 'mailto:' . $r->email . '?subject=' . rawurlencode('Re: your EIAAW Social Media Team enquiry'))
                    ->openUrlInNewTab(),
                \Filament\Actions\Action::make('markContacted')
                    ->label('Mark contacted')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (SupportEnquiry $r) => $r->status === 'new')
                    ->action(function (SupportEnquiry $r): void {
                        $r->update(['status' => 'contacted', 'handled_at' => now()]);
                        \Filament\Notifications\Notification::make()->title('Marked contacted')->success()->send();
                    }),
                \Filament\Actions\Action::make('close')
                    ->label('Close')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (SupportEnquiry $r) => $r->status !== 'closed')
                    ->action(function (SupportEnquiry $r): void {
                        $r->update(['status' => 'closed', 'handled_at' => now()]);
                        \Filament\Notifications\Notification::make()->title('Closed')->send();
                    }),
            ])
            ->emptyStateHeading('No enquiries yet')
            ->emptyStateDescription('Leads from the "Talk to us" form on the landing page and inside the panels will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedInbox);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSupportEnquiries::route('/'),
        ];
    }
}
