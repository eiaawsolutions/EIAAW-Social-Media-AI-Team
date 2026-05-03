<?php

namespace App\Filament\Agency\Resources\Drafts\Pages;

use App\Filament\Agency\Resources\Drafts\DraftResource;
use App\Models\Brand;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;

class ManageDrafts extends ManageRecords
{
    protected static string $resource = DraftResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetAllAtCap')
                ->label('Reset all at-cap & retry')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->modalHeading('Reset attempt counters on all at-cap failed drafts')
                ->modalDescription(
                    'Use after a Writer/Compliance prompt fix or after enriching the brand corpus. '
                    . 'Zeroes the per-draft attempt counter on every compliance_failed draft that has hit the cap, '
                    . 'and queues fresh redrafts. Each draft will run up to '.\App\Jobs\RedraftFailedDraft::MAX_REVISIONS.' more attempts (~$0.02–0.05 each).'
                )
                ->schema([
                    TextInput::make('limit')
                        ->label('Max drafts to reset (safety cap)')
                        ->numeric()
                        ->default(20)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $ws = $this->workspace();
                    if (! $ws) {
                        Notification::make()->title('No workspace')->danger()->send();
                        return;
                    }
                    $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

                    $candidates = Draft::whereIn('brand_id', $brandIds)
                        ->where('status', 'compliance_failed')
                        ->where('revision_count', '>=', \App\Jobs\RedraftFailedDraft::MAX_REVISIONS)
                        ->whereNotNull('calendar_entry_id')
                        ->orderBy('id')
                        ->limit((int) $data['limit'])
                        ->get(['id']);

                    if ($candidates->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to reset')
                            ->body('No at-cap failed drafts to retry.')
                            ->warning()
                            ->send();
                        return;
                    }

                    Draft::whereIn('id', $candidates->pluck('id'))->update([
                        'revision_count' => 0,
                        'last_redraft_at' => null,
                    ]);
                    foreach ($candidates as $d) {
                        \App\Jobs\RedraftFailedDraft::dispatch($d->id);
                    }

                    Notification::make()
                        ->title("Reset and queued {$candidates->count()} draft(s)")
                        ->body('Refresh in ~1–2 minutes.')
                        ->success()
                        ->send();
                }),

            Action::make('redraftAllFailed')
                ->label('Redraft all failed')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->modalHeading('Auto-redraft every compliance-failed draft')
                ->modalDescription(
                    'Dispatches the Writer to fix each compliance-failed draft (under the per-draft retry cap). '
                    . 'Compliance re-runs automatically. The cron also runs this every 5 minutes — '
                    . 'this button is for when you want it to happen now.'
                )
                ->schema([
                    TextInput::make('limit')
                        ->label('Max drafts to redraft (safety cap — each costs ~$0.02–0.05)')
                        ->numeric()
                        ->default(20)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $ws = $this->workspace();
                    if (! $ws) {
                        Notification::make()->title('No workspace')->danger()->send();
                        return;
                    }
                    $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

                    $candidates = Draft::whereIn('brand_id', $brandIds)
                        ->where('status', 'compliance_failed')
                        ->where('revision_count', '<', \App\Jobs\RedraftFailedDraft::MAX_REVISIONS)
                        ->whereNotNull('calendar_entry_id')
                        ->orderBy('id')
                        ->limit((int) $data['limit'])
                        ->get(['id']);

                    if ($candidates->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to redraft')
                            ->body('No compliance-failed drafts under the retry cap.')
                            ->warning()
                            ->send();
                        return;
                    }

                    foreach ($candidates as $d) {
                        \App\Jobs\RedraftFailedDraft::dispatch($d->id);
                    }

                    Notification::make()
                        ->title("Queued {$candidates->count()} redraft(s)")
                        ->body('The Writer is fixing them now. Refresh in ~1–2 minutes to see results.')
                        ->success()
                        ->send();
                }),

            Action::make('scheduleAllApproved')
                ->label('Schedule all approved')
                ->icon('heroicon-o-rocket-launch')
                ->color('primary')
                ->modalHeading('Schedule every approved draft')
                ->modalDescription(
                    'Picks every draft in status=approved that has no live scheduled post yet. '
                    . 'Spreads them across the chosen window so you don\'t spam the feeds. '
                    . 'Brand timezone is honoured.'
                )
                ->schema([
                    Radio::make('mode')
                        ->label('How to space them out')
                        ->options([
                            'now_stagger' => 'Now + 5 min apart (fastest)',
                            'hourly' => 'One per hour starting in 1 hour',
                            'daily_9am' => 'One per day at 9 AM brand time, starting tomorrow',
                            'custom_start_30min' => 'Start at custom time, 30 min apart',
                        ])
                        ->default('hourly')
                        ->required(),
                    DateTimePicker::make('custom_start')
                        ->label(fn () => 'Custom start (' . ($this->brandTimezone() ?: 'UTC') . ')')
                        ->seconds(false)
                        ->timezone(fn () => $this->brandTimezone() ?: 'UTC')
                        ->default(fn () => Carbon::now($this->brandTimezone() ?: 'UTC')->addHour())
                        ->minDate(fn () => Carbon::now($this->brandTimezone() ?: 'UTC'))
                        ->visible(fn (callable $get) => $get('mode') === 'custom_start_30min'),
                    TextInput::make('limit')
                        ->label('Max drafts to schedule (safety cap)')
                        ->numeric()
                        ->default(30)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $ws = $this->workspace();
                    if (! $ws) {
                        Notification::make()->title('No workspace')->danger()->send();
                        return;
                    }
                    $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');
                    $brandTz = $this->brandTimezone() ?: 'UTC';

                    // Find approved drafts WITHOUT a live scheduled post.
                    $candidates = Draft::whereIn('brand_id', $brandIds)
                        ->where('status', 'approved')
                        ->whereDoesntHave('scheduledPosts', function ($q) {
                            $q->whereIn('status', ['queued', 'submitting', 'submitted', 'published']);
                        })
                        ->orderBy('id')
                        ->limit((int) $data['limit'])
                        ->get();

                    if ($candidates->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to schedule')
                            ->body('No approved drafts without a live scheduled post.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $scheduled = 0;
                    $skipped = 0;
                    $cursor = $this->cursorStart($data['mode'], $data['custom_start'] ?? null, $brandTz);
                    $stepMin = $this->stepMinutes($data['mode']);

                    foreach ($candidates as $i => $draft) {
                        $connection = PlatformConnection::where('brand_id', $draft->brand_id)
                            ->where('platform', $draft->platform)
                            ->where('status', 'active')
                            ->first();
                        if (! $connection) {
                            $skipped++;
                            continue;
                        }

                        $when = $cursor->copy();
                        ScheduledPost::create([
                            'draft_id' => $draft->id,
                            'brand_id' => $draft->brand_id,
                            'platform_connection_id' => $connection->id,
                            'scheduled_for' => $when,
                            'status' => 'queued',
                            'attempt_count' => 0,
                        ]);
                        $draft->update(['status' => 'scheduled']);
                        $scheduled++;

                        // Advance cursor for next draft.
                        if ($data['mode'] === 'daily_9am') {
                            $cursor = $cursor->copy()->addDay();
                        } else {
                            $cursor = $cursor->copy()->addMinutes($stepMin);
                        }
                    }

                    Notification::make()
                        ->title("Scheduled {$scheduled} draft(s)")
                        ->body($skipped > 0
                            ? "{$skipped} skipped — no active platform connection. Reconnect on /agency/platform-connections."
                            : 'Cron picks them up at each scheduled_for time. Watch /agency/scheduled-posts.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function cursorStart(string $mode, $customStart, string $brandTz): Carbon
    {
        return match ($mode) {
            'now_stagger' => Carbon::now()->addMinutes(5),
            'hourly' => Carbon::now()->addHour()->minute(0)->second(0),
            'daily_9am' => Carbon::tomorrow($brandTz)->setTime(9, 0)->utc(),
            'custom_start_30min' => $customStart
                ? Carbon::parse($customStart)
                : Carbon::now()->addMinutes(30),
            default => Carbon::now()->addMinutes(15),
        };
    }

    private function stepMinutes(string $mode): int
    {
        return match ($mode) {
            'now_stagger' => 5,
            'hourly' => 60,
            'daily_9am' => 0, // handled separately (addDay)
            'custom_start_30min' => 30,
            default => 30,
        };
    }

    private function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    private function brandTimezone(): ?string
    {
        $ws = $this->workspace();
        if (! $ws) return null;
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->value('timezone');
    }
}
