<?php

namespace App\Filament\Agency\Resources\CalendarEntries;

use App\Filament\Agency\Resources\CalendarEntries\Pages\ManageCalendarEntries;
use App\Models\CalendarEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
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
            ->searchPlaceholder('Search topic...')
            ->persistFiltersInSession()
            ->persistSearchInSession()
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
            ->filters([
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Platform')
                    ->multiple()
                    ->options([
                        'instagram' => 'Instagram',
                        'facebook' => 'Facebook',
                        'linkedin' => 'LinkedIn',
                        'tiktok' => 'TikTok',
                        'threads' => 'Threads',
                        'x' => 'X (Twitter)',
                        'youtube' => 'YouTube',
                        'pinterest' => 'Pinterest',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }
                        // platforms is a JSON array on calendar_entries; match
                        // when any selected platform appears in the array.
                        return $query->where(function (Builder $q) use ($values) {
                            foreach ($values as $v) {
                                $q->orWhereJsonContains('platforms', $v);
                            }
                        });
                    }),

                Tables\Filters\SelectFilter::make('pillar')
                    ->label('Pillar')
                    ->multiple()
                    ->options([
                        'educational' => 'Educational',
                        'community' => 'Community',
                        'promotional' => 'Promotional',
                        'behind_the_scenes' => 'Behind the scenes',
                        'thought_leadership' => 'Thought leadership',
                    ]),

                Tables\Filters\SelectFilter::make('format')
                    ->label('Format')
                    ->multiple()
                    ->options([
                        'image' => 'Image',
                        'carousel' => 'Carousel',
                        'reel' => 'Reel',
                        'video' => 'Video',
                        'story' => 'Story',
                        'text' => 'Text',
                    ]),

                Tables\Filters\Filter::make('scheduled_date_range')
                    ->label('Scheduled date')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->native(false)
                            ->closeOnDateSelection(),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('To')
                            ->native(false)
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('scheduled_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('scheduled_date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = \Filament\Tables\Filters\Indicator::make('From: ' . \Illuminate\Support\Carbon::parse($data['from'])->format('M j, Y'))
                                ->removeField('from');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = \Filament\Tables\Filters\Indicator::make('To: ' . \Illuminate\Support\Carbon::parse($data['until'])->format('M j, Y'))
                                ->removeField('until');
                        }
                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(4)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                // "Draft this" fans out one DraftCalendarEntry job per LISTED
                // platform (not just the first). Each job runs the full
                // Writer -> Designer -> (Video) -> Compliance chain on the
                // `drafting` queue, is idempotent (skips an existing non-rejected
                // draft for that (entry, platform)), and respects the per-workspace
                // daily cap — identical to "Re-evaluate" and the header
                // "Draft all". This is the action the operator reaches for after
                // Edit: it must produce a draft for EVERY selected platform, which
                // the old first-platform-only synchronous run did not. Running
                // async also avoids the 180s request wall on heavy entries
                // (Researcher + Writer + Designer + Video + Compliance x N).
                \Filament\Actions\Action::make('runWriter')
                    ->label('Draft this')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Draft this entry on every listed platform?')
                    ->modalDescription(fn (CalendarEntry $r) => 'Fans out one job per platform: '
                        . (is_array($r->platforms) && $r->platforms ? implode(', ', $r->platforms) : '— none listed —')
                        . '. Each runs Writer + Designer + Compliance. Drafts land on /agency/drafts; daily caps enforced.')
                    ->action(function (CalendarEntry $r): void {
                        $platforms = is_array($r->platforms) ? array_values(array_filter(
                            $r->platforms,
                            fn ($p) => is_string($p) && $p !== '',
                        )) : [];
                        if (! $platforms) {
                            \Filament\Notifications\Notification::make()
                                ->title('Entry has no platforms')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Clear rejected drafts so DraftCalendarEntry's idempotency
                        // gate ("skip if a non-rejected draft exists") re-runs for
                        // them; live drafts (awaiting_approval / approved / scheduled
                        // / published) are preserved so in-flight work isn't trashed.
                        $r->drafts()->where('status', 'rejected')->delete();

                        $dispatched = 0;
                        $skipped = 0;
                        foreach ($platforms as $platform) {
                            $hasDraft = $r->drafts()
                                ->where('platform', $platform)
                                ->whereNotIn('status', ['rejected'])
                                ->exists();
                            if ($hasDraft) {
                                $skipped++;
                                continue;
                            }
                            \App\Jobs\DraftCalendarEntry::dispatch($r->id, $platform)
                                ->onQueue('drafting');
                            $dispatched++;
                        }

                        if ($dispatched === 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('Already drafted')
                                ->body(sprintf(
                                    'All %d platform(s) already have a draft. Use "Re-evaluate" to force a re-draft.',
                                    $skipped,
                                ))
                                ->info()
                                ->send();
                            return;
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Drafting')
                            ->body(sprintf(
                                'Dispatched %d job(s)%s; watch /agency/drafts as they land.',
                                $dispatched,
                                $skipped > 0 ? sprintf(', skipped %d already-drafted', $skipped) : '',
                            ))
                            ->success()
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

                // Re-evaluate: FORCE a fresh re-draft on every platform. Unlike
                // "Draft this" (which only fills platforms that have no draft
                // yet), this clears every NON-LIVE draft first — rejected AND
                // held (compliance_pending / compliance_failed / awaiting_approval)
                // — so DraftCalendarEntry's "skip if a non-rejected draft exists"
                // gate no longer short-circuits and the chain genuinely re-runs.
                // Live drafts (approved / scheduled / published) are preserved so
                // work in flight is never trashed. This is the "recover from a bad
                // batch" action; "Draft this" is the everyday fill-the-gaps action.
                \Filament\Actions\Action::make('reEvaluate')
                    ->label('Re-evaluate')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Force a fresh re-draft on every listed platform?')
                    ->modalDescription('Clears existing rejected AND held (unapproved) drafts first, then re-runs Writer + Designer + Compliance per platform. Approved/scheduled/published drafts are kept. Daily caps enforced.')
                    ->action(function (CalendarEntry $r): void {
                        $platforms = is_array($r->platforms) ? array_values(array_filter(
                            $r->platforms,
                            fn ($p) => is_string($p) && $p !== '',
                        )) : [];
                        if (! $platforms) {
                            \Filament\Notifications\Notification::make()->title('Entry has no platforms')->danger()->send();
                            return;
                        }
                        // Clear every NON-LIVE draft (rejected + held) so the
                        // idempotency gate re-runs for all of them. Live drafts
                        // (approved/scheduled/published) are preserved so we don't
                        // trash work in flight.
                        $r->drafts()
                            ->whereIn('status', ['rejected', 'compliance_pending', 'compliance_failed', 'awaiting_approval'])
                            ->delete();

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

        // Tenant isolation: a user with no resolvable workspace sees nothing
        // (prevents cross-tenant IDOR). NO super-admin bypass (removed
        // 2026-06-02): the Agency panel is hard own-workspace for EVERYONE,
        // including HQ, which administers other tenants from /admin. See
        // BrandResource for the full rationale.
        if (! $workspaceId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at')->where('workspace_id', $workspaceId);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCalendarEntries::route('/'),
        ];
    }
}
