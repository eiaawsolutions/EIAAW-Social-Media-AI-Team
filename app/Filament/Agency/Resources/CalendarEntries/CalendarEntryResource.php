<?php

namespace App\Filament\Agency\Resources\CalendarEntries;

use App\Filament\Agency\Resources\CalendarEntries\Pages\ManageCalendarEntries;
use App\Models\CalendarEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Calendar — the month of post ideas the Strategist agent produced.
 *
 * Stage 06 detector flips green when a ContentCalendar exists in
 * status in_review/approved with at least one entry. This page exposes
 * those entries so the user can see what's planned, run the Writer on
 * any specific entry/platform, and visually confirm the pillar/format
 * mix the Strategist produced.
 *
 * Per-row actions:
 *   - Run Writer on this entry (picks the entry's first listed platform).
 *
 * v1.1 follow-ups: edit entry inline (topic/angle/platforms), drag to
 * reschedule on a real calendar grid, delete entry, regenerate.
 */
class CalendarEntryResource extends Resource
{
    protected static ?string $model = CalendarEntry::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static ?string $navigationLabel = 'Calendar';
    protected static ?string $modelLabel = 'Calendar entry';
    protected static ?string $pluralModelLabel = 'Calendar';
    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_date')
            ->columns([
                Tables\Columns\TextColumn::make('scheduled_date')
                    ->label('Day')
                    ->date('M j')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\TextColumn::make('topic')
                    ->wrap()
                    ->limit(180)
                    ->extraHeaderAttributes(['style' => 'min-width: 320px;'])
                    ->extraAttributes(['style' => 'max-width: 480px;'])
                    ->searchable(),
                Tables\Columns\TextColumn::make('pillar')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'educational' => 'info',
                        'community' => 'success',
                        'promotional' => 'warning',
                        'behind_the_scenes' => 'gray',
                        'thought_leadership' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', ucfirst($state))),
                Tables\Columns\TextColumn::make('format')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),
                Tables\Columns\TextColumn::make('platforms')
                    ->badge()
                    ->separator(',')
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(',', $state) : (string) $state),
                Tables\Columns\TextColumn::make('objective')
                    ->color('gray')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('drafts_count')
                    ->counts('drafts')
                    ->label('Drafts')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('runWriter')
                    ->label('Draft this')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Run Writer on this entry?')
                    ->modalDescription(fn (CalendarEntry $r) => 'Will draft a post for the first listed platform: ' . (is_array($r->platforms) ? ($r->platforms[0] ?? '?') : '?'))
                    ->action(function (CalendarEntry $r): void {
                        @set_time_limit(180);
                        $platforms = is_array($r->platforms) ? $r->platforms : [];
                        $platform = $platforms[0] ?? null;
                        if (! $platform) {
                            \Filament\Notifications\Notification::make()->title('Entry has no platform')->danger()->send();
                            return;
                        }
                        try {
                            $writer = app(\App\Agents\WriterAgent::class)->run($r->brand, [
                                'calendar_entry_id' => $r->id,
                                'platform' => $platform,
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Writer crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->persistent()
                                ->send();
                            return;
                        }
                        if (! $writer->ok) {
                            \Filament\Notifications\Notification::make()
                                ->title('Writer could not draft')
                                ->body($writer->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();
                            return;
                        }
                        // Generate the image. Soft-fail: if Designer errors,
                        // the draft survives as text-only and the user can
                        // re-run from /agency/drafts.
                        try {
                            app(\App\Agents\DesignerAgent::class)->run($r->brand, [
                                'draft_id' => $writer->data['draft_id'],
                            ]);
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('Calendar: Designer crashed', [
                                'draft_id' => $writer->data['draft_id'],
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Video gate: format is reel/video/story AND platform
                        // accepts video. The still becomes the i2v keyframe.
                        $needsVideo = in_array((string) ($r->format ?? ''), ['reel', 'video', 'story'], true)
                            && \App\Services\Imagery\FalAiClient::platformAcceptsVideo($platform);
                        if ($needsVideo) {
                            try {
                                app(\App\Agents\VideoAgent::class)->run($r->brand, [
                                    'draft_id' => $writer->data['draft_id'],
                                ]);
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Calendar: VideoAgent crashed', [
                                    'draft_id' => $writer->data['draft_id'],
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Hand off to Compliance so the draft lands in a usable
                        // state on the Drafts page (awaiting_approval /
                        // approved / compliance_failed).
                        try {
                            $compl = app(\App\Agents\ComplianceAgent::class)->run($r->brand, [
                                'draft_id' => $writer->data['draft_id'],
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Drafted, but Compliance crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->warning()
                                ->send();
                            return;
                        }
                        $passed = ! empty($compl->data['all_passed']);
                        \Filament\Notifications\Notification::make()
                            ->title($passed ? 'Draft ready' : 'Draft written but Compliance held it')
                            ->body(sprintf(
                                'Draft #%d for %s — status: %s',
                                $writer->data['draft_id'],
                                $platform,
                                $compl->data['new_status'] ?? '?',
                            ))
                            ->color($passed ? 'success' : 'warning')
                            ->send();
                    }),

                // Edit the entry's topic/angle/format/platforms in place. Lets
                // the operator clean up a Strategist-generated entry that
                // misread the brand before re-running the Writer.
                \Filament\Actions\Action::make('editEntry')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('topic')
                            ->label('Topic')
                            ->default(fn (CalendarEntry $r) => $r->topic)
                            ->required()
                            ->maxLength(255),
                        \Filament\Forms\Components\Textarea::make('angle')
                            ->label('Angle')
                            ->default(fn (CalendarEntry $r) => $r->angle)
                            ->rows(3),
                        \Filament\Forms\Components\Textarea::make('visual_direction')
                            ->label('Visual direction')
                            ->default(fn (CalendarEntry $r) => $r->visual_direction)
                            ->rows(3),
                        \Filament\Forms\Components\Select::make('format')
                            ->label('Format')
                            ->options([
                                'image' => 'Image',
                                'carousel' => 'Carousel',
                                'reel' => 'Reel',
                                'video' => 'Video',
                                'story' => 'Story',
                                'text' => 'Text',
                            ])
                            ->default(fn (CalendarEntry $r) => $r->format)
                            ->required(),
                    ])
                    ->action(function (CalendarEntry $r, array $data): void {
                        $r->update([
                            'topic' => $data['topic'],
                            'angle' => $data['angle'] ?? $r->angle,
                            'visual_direction' => $data['visual_direction'] ?? $r->visual_direction,
                            'format' => $data['format'],
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Entry updated')
                            ->body('Re-run "Draft this" to regenerate with the new brief.')
                            ->success()
                            ->send();
                    }),

                // Re-evaluate: clear any rejected/failed-compliance drafts for
                // this entry and re-fan-out one DraftCalendarEntry job per
                // platform. Clean way to recover from a bad batch.
                \Filament\Actions\Action::make('reEvaluate')
                    ->label('Re-evaluate')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Re-draft this entry on every listed platform?')
                    ->modalDescription('Existing rejected drafts get cleared first; jobs fan out one per (entry, platform). Cost: ~$0.04 per image + Writer LLM. Daily caps enforced.')
                    ->action(function (CalendarEntry $r): void {
                        $platforms = is_array($r->platforms) ? $r->platforms : [];
                        if (! $platforms) {
                            \Filament\Notifications\Notification::make()->title('Entry has no platforms')->danger()->send();
                            return;
                        }
                        // Clear rejected drafts so DraftCalendarEntry's idempotency
                        // check ('skip if a non-rejected draft exists') will
                        // re-run; live drafts (awaiting_approval/approved/scheduled/published)
                        // are preserved so we don't trash work in flight.
                        $r->drafts()->where('status', 'rejected')->delete();

                        $dispatched = 0;
                        foreach ($platforms as $platform) {
                            \App\Jobs\DraftCalendarEntry::dispatch($r->id, $platform)
                                ->onQueue('drafting');
                            $dispatched++;
                        }
                        \Filament\Notifications\Notification::make()
                            ->title('Re-drafting')
                            ->body("Dispatched {$dispatched} job(s); watch /agency/drafts as they land.")
                            ->success()
                            ->send();
                    }),

                // Delete the entry. Cascades to drafts (FK on delete cascade)
                // and through to scheduled_posts. Use this to prune a bad
                // Strategist plan item that doesn't deserve a re-draft.
                \Filament\Actions\Action::make('deleteEntry')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete this calendar entry?')
                    ->modalDescription('Removes the entry and any drafts it spawned. Already-scheduled or published rows are preserved (only the link to this entry is severed).')
                    ->action(function (CalendarEntry $r): void {
                        // Detach scheduled/published drafts so they survive.
                        $r->drafts()
                            ->whereIn('status', ['scheduled', 'published', 'approved'])
                            ->update(['calendar_entry_id' => null]);
                        // Hard-delete the rest along with the entry.
                        $r->drafts()
                            ->whereIn('status', ['awaiting_approval', 'rejected', 'compliance_failed'])
                            ->delete();
                        $r->delete();
                        \Filament\Notifications\Notification::make()
                            ->title('Entry deleted')
                            ->body('Live drafts preserved; planning rows removed.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No calendar yet')
            ->emptyStateDescription('Run the Strategist on the Setup wizard to plan a month.')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
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
            'index' => ManageCalendarEntries::route('/'),
        ];
    }
}
