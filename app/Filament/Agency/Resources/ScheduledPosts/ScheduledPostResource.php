<?php

namespace App\Filament\Agency\Resources\ScheduledPosts;

use App\Filament\Agency\Resources\ScheduledPosts\Pages\ManageScheduledPosts;
use App\Models\ScheduledPost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Schedule — every queued / submitted / published post, with status,
 * last error, and links to the underlying draft + platform post.
 *
 * Stage 08 detector flips green when a row exists in queued/submitting/
 * submitted/published. v1 publishing is via Blotato — the SchedulerWorker
 * picks up queued rows on schedule, calls Blotato, captures the
 * blotato_post_id + platform_post_url. v2 will expose Pause / Resume
 * for an entire workspace queue.
 */
class ScheduledPostResource extends Resource
{
    protected static ?string $model = ScheduledPost::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;
    protected static ?string $navigationLabel = 'Schedule';
    protected static ?string $modelLabel = 'Scheduled post';
    protected static ?string $pluralModelLabel = 'Schedule';
    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_for')
            ->columns([
                Tables\Columns\TextColumn::make('scheduled_for')
                    ->label('When')
                    ->dateTime('M j · H:i')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\TextColumn::make('draft.platform')
                    ->label('Platform')
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
                    }),
                Tables\Columns\TextColumn::make('draft.body')
                    ->label('Caption')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'queued' => 'gray',
                        'submitting' => 'warning',
                        'submitted' => 'info',
                        'published' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('attempt_count')
                    ->label('Attempts')
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('0'),
                Tables\Columns\TextColumn::make('platform_post_url')
                    ->label('Live URL')
                    ->url(fn ($state) => $state)
                    ->openUrlInNewTab()
                    ->limit(28)
                    ->placeholder('—')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Last error')
                    ->limit(60)
                    ->color('danger')
                    ->placeholder('—')
                    ->visible(fn () => true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'queued' => 'Queued',
                        'submitting' => 'Submitting',
                        'submitted' => 'Submitted',
                        'published' => 'Published',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn (ScheduledPost $r) => $r->status === 'queued')
                    ->form([
                        \Filament\Forms\Components\DateTimePicker::make('scheduled_for')
                            ->label('Publish at')
                            ->seconds(false)
                            ->default(fn (ScheduledPost $r) => $r->scheduled_for)
                            ->minDate(now())
                            ->required(),
                    ])
                    ->action(function (ScheduledPost $r, array $data): void {
                        $r->update(['scheduled_for' => $data['scheduled_for']]);
                        \Filament\Notifications\Notification::make()
                            ->title('Rescheduled')
                            ->body('Now publishing at ' . \Illuminate\Support\Carbon::parse($data['scheduled_for'])->format('M j, H:i'))
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ScheduledPost $r) => in_array($r->status, ['queued', 'failed']))
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this scheduled post?')
                    ->modalDescription('The post will not publish. The underlying draft moves back to "approved" so you can reschedule it later.')
                    ->action(function (ScheduledPost $r): void {
                        $r->update(['status' => 'cancelled']);
                        // Bring the draft back to approved so the user can re-schedule
                        if ($r->draft && $r->draft->status === 'scheduled') {
                            $r->draft->update(['status' => 'approved']);
                        }
                        \Filament\Notifications\Notification::make()
                            ->title('Cancelled')
                            ->body('Draft is back to "approved" — reschedule from /agency/drafts.')
                            ->warning()
                            ->send();
                    }),

                \Filament\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (ScheduledPost $r) => $r->status === 'failed' && $r->attempt_count < 3)
                    ->requiresConfirmation()
                    ->action(function (ScheduledPost $r): void {
                        $r->update([
                            'status' => 'queued',
                            'last_error' => null,
                            'scheduled_for' => now()->addMinutes(5),
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Re-queued')
                            ->body('Will retry in 5 minutes.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Nothing scheduled')
            ->emptyStateDescription('Approve and schedule a draft on /agency/drafts to queue it for publishing.')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDateRange);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at');
                if ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScheduledPosts::route('/'),
        ];
    }
}
