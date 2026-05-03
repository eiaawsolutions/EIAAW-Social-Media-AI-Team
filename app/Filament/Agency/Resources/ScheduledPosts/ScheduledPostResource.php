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
                    ->label('When (brand TZ)')
                    ->formatStateUsing(function ($state, ScheduledPost $r) {
                        if (! $state) return '—';
                        $tz = $r->brand?->timezone ?: 'UTC';
                        return \Illuminate\Support\Carbon::parse($state)
                            ->setTimezone($tz)
                            ->format('M j · H:i') . ' ' . substr($tz, strrpos($tz, '/') + 1);
                    })
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
                    ->limit(220)
                    ->extraHeaderAttributes(['style' => 'min-width: 360px;'])
                    ->extraAttributes(['style' => 'max-width: 520px;'])
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
                Tables\Columns\TextColumn::make('next_action')
                    ->label('Next step')
                    ->state(fn (ScheduledPost $r) => self::nextActionFor($r))
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'WAIT') => 'gray',
                        str_starts_with($state, 'YOU:') => 'warning',
                        str_starts_with($state, 'AUTO') => 'info',
                        str_starts_with($state, 'DONE') => 'success',
                        str_starts_with($state, 'FAIL') => 'danger',
                        default => 'gray',
                    })
                    ->wrap()
                    ->extraAttributes(['style' => 'max-width: 280px;']),
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
                    ->wrap()
                    ->limit(180)
                    ->color('danger')
                    ->extraAttributes(['style' => 'max-width: 420px;'])
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
                    ->schema([
                        \Filament\Forms\Components\DateTimePicker::make('scheduled_for')
                            ->label(fn (ScheduledPost $r) => 'Publish at (' . ($r->brand?->timezone ?: 'UTC') . ')')
                            ->helperText(fn (ScheduledPost $r) => 'Brand timezone: ' . ($r->brand?->timezone ?: 'UTC') . '. Stored as UTC.')
                            ->seconds(false)
                            ->timezone(fn (ScheduledPost $r) => $r->brand?->timezone ?: 'UTC')
                            ->default(fn (ScheduledPost $r) => $r->scheduled_for)
                            ->minDate(fn (ScheduledPost $r) => now($r->brand?->timezone ?: 'UTC'))
                            ->required(),
                    ])
                    ->action(function (ScheduledPost $r, array $data): void {
                        $newAt = \Illuminate\Support\Carbon::parse($data['scheduled_for']);
                        $brandTz = $r->brand?->timezone ?: 'UTC';
                        $r->update(['scheduled_for' => $newAt]);
                        \Filament\Notifications\Notification::make()
                            ->title('Rescheduled')
                            ->body(sprintf(
                                'Now publishing at %s %s (= %s UTC).',
                                $newAt->copy()->setTimezone($brandTz)->format('M j, H:i'),
                                $brandTz,
                                $newAt->format('M j, H:i'),
                            ))
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

                // Re-evaluate: regenerate the underlying draft (Writer + Designer
                // + Compliance), reset attempt counter, requeue. Use this after
                // fixing whatever broke (Blotato config, image quality, etc.).
                \Filament\Actions\Action::make('reEvaluate')
                    ->label('Re-evaluate')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (ScheduledPost $r) => in_array($r->status, ['failed', 'cancelled']))
                    ->requiresConfirmation()
                    ->modalHeading('Re-run Writer + Designer + Compliance on this draft?')
                    ->modalDescription('Regenerates the caption and image for the underlying draft, then re-queues this scheduled post in 5 minutes. Costs ~$0.04 (image) + a few cents (LLM). Burns one image-cap unit.')
                    ->action(function (ScheduledPost $r): void {
                        @set_time_limit(180);
                        $draft = $r->draft;
                        if (! $draft) {
                            \Filament\Notifications\Notification::make()->title('Draft missing')->danger()->send();
                            return;
                        }
                        // Clear the asset so DesignerAgent regenerates instead
                        // of no-op'ing on the existing one.
                        $draft->update(['asset_url' => null, 'status' => 'awaiting_approval']);

                        try {
                            app(\App\Agents\WriterAgent::class)->run($draft->brand, [
                                'calendar_entry_id' => $draft->calendar_entry_id,
                                'platform' => $draft->platform,
                                'draft_id' => $draft->id,
                            ]);
                            app(\App\Agents\DesignerAgent::class)->run($draft->brand, ['draft_id' => $draft->id]);
                            app(\App\Agents\ComplianceAgent::class)->run($draft->brand, ['draft_id' => $draft->id]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Re-evaluate hit an error')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->persistent()
                                ->send();
                            return;
                        }

                        $r->update([
                            'status' => 'queued',
                            'attempt_count' => 0,
                            'last_error' => null,
                            'blotato_post_id' => null,
                            'scheduled_for' => now()->addMinutes(5),
                        ]);
                        $draft->update(['status' => 'scheduled']);

                        \Filament\Notifications\Notification::make()
                            ->title('Re-evaluated')
                            ->body('Draft regenerated; will publish in 5 minutes.')
                            ->success()
                            ->send();
                    }),

                // Edit caption — quick fix for failed/cancelled posts where the
                // body needs a small tweak (typo, wrong CTA) before retrying.
                \Filament\Actions\Action::make('editCaption')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (ScheduledPost $r) => in_array($r->status, ['queued', 'failed', 'cancelled']) && $r->draft)
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('body')
                            ->label('Caption')
                            ->default(fn (ScheduledPost $r) => $r->draft?->body)
                            ->rows(8)
                            ->required(),
                    ])
                    ->action(function (ScheduledPost $r, array $data): void {
                        if (! $r->draft) return;
                        $r->draft->update(['body' => $data['body']]);
                        \Filament\Notifications\Notification::make()
                            ->title('Caption updated')
                            ->body('The next publish attempt will use the new caption.')
                            ->success()
                            ->send();
                    }),

                // Hard delete — removes the row from the schedule. Only allowed
                // on terminal states so we never delete an in-flight post.
                \Filament\Actions\Action::make('deleteRow')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (ScheduledPost $r) => in_array($r->status, ['failed', 'cancelled']))
                    ->requiresConfirmation()
                    ->modalHeading('Delete this scheduled post?')
                    ->modalDescription('Removes the row from the schedule. The underlying draft is preserved (rolls back to "approved"). Use this for cleanup after a publish failure has been resolved elsewhere.')
                    ->action(function (ScheduledPost $r): void {
                        if ($r->draft && $r->draft->status === 'scheduled') {
                            $r->draft->update(['status' => 'approved']);
                        }
                        $r->delete();
                        \Filament\Notifications\Notification::make()
                            ->title('Deleted')
                            ->body('Scheduled post removed; draft is back to "approved".')
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

    /**
     * Plain-English next step per scheduled post. Same actor convention as
     * DraftResource::nextActionFor — operator scans the column to know what
     * to click vs what's already running.
     */
    public static function nextActionFor(ScheduledPost $post): string
    {
        $now = \Carbon\Carbon::now();

        return match ($post->status) {
            'queued' => $post->scheduled_for && $post->scheduled_for->isFuture()
                ? 'AUTO: cron will dispatch at scheduled_for ('
                    . $post->scheduled_for->diffForHumans() . ')'
                : 'AUTO: cron picks this up within 1 minute',
            'submitting' => 'WAIT for Blotato to accept (auto)',
            'submitted' => 'WAIT for Blotato status poll → published / failed',
            'published' => 'DONE — view on Live feed',
            'failed' => $post->attempt_count < 3
                ? 'AUTO: cron will retry in ~5 min (attempt ' . ($post->attempt_count + 1) . '/3)'
                : 'FAIL — manual: click Retry or fix root cause + resubmit',
            'cancelled' => 'DONE — cancelled; draft is back to approved',
            default => '—',
        };
    }
}
