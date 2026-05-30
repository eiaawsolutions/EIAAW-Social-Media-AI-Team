<?php

namespace App\Filament\Agency\Resources\ScheduledPosts\Pages;

use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageScheduledPosts extends ManageRecords
{
    protected static string $resource = ScheduledPostResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getSubheading(): ?string
    {
        return 'Approved drafts queued for their scheduled time. Pause publishing here if a brand crisis hits.';
    }

    protected function getHeaderActions(): array
    {
        $workspace = $this->resolveWorkspace();
        $paused = $workspace?->publishing_paused ?? false;

        return [
            Action::make('pausePublishing')
                ->label('Stop the AI')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn () => ! $paused)
                ->requiresConfirmation()
                ->modalHeading('Stop the AI for this workspace?')
                ->modalDescription('This is the master kill switch. The AI stops BOTH ways: it will generate no new content (the daily autopilot skips this workspace) AND every queued post stays queued instead of publishing. Nothing resumes until you click Resume. Use this for a brand crisis, pre-launch staging, or any time you want to take back the wheel.')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('reason')
                        ->label('Reason (audit trail)')
                        ->maxLength(255)
                        ->default('Operator pause'),
                ])
                ->action(function (array $data) use ($workspace): void {
                    if (! $workspace) {
                        Notification::make()->title('No workspace')->danger()->send();
                        return;
                    }
                    $workspace->update([
                        'publishing_paused' => true,
                        'publishing_paused_at' => now(),
                        'publishing_paused_reason' => $data['reason'] ?? 'Operator pause',
                    ]);
                    Notification::make()
                        ->title('AI stopped')
                        ->body('No new content will be generated and queued posts will not publish until you click Resume.')
                        ->warning()
                        ->send();
                }),

            Action::make('resumePublishing')
                ->label('Resume the AI')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn () => $paused)
                ->requiresConfirmation()
                ->modalHeading('Resume the AI?')
                ->modalDescription('The autopilot starts generating fresh content again on its next hourly run, and queued posts whose scheduled time has passed publish on the next minute tick.')
                ->action(function () use ($workspace): void {
                    if (! $workspace) return;
                    $workspace->update([
                        'publishing_paused' => false,
                        'publishing_paused_at' => null,
                        'publishing_paused_reason' => null,
                    ]);
                    Notification::make()
                        ->title('AI resumed')
                        ->body('Content generation resumes within the hour; the publish queue ticks on the next minute.')
                        ->success()
                        ->send();
                }),

            Action::make('cancelAllQueued')
                ->label('Cancel all queued')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->outlined()
                ->requiresConfirmation()
                ->modalHeading('Cancel every queued/submitted post in this workspace?')
                ->modalDescription('Each cancelled scheduled_post rolls its draft back to "approved" so you can re-schedule it later from /agency/drafts.')
                ->action(function () use ($workspace): void {
                    if (! $workspace) return;
                    $count = 0;
                    $rows = \App\Models\ScheduledPost::whereHas('brand', fn ($q) => $q->where('workspace_id', $workspace->id))
                        ->whereIn('status', ['queued', 'submitted'])
                        ->with('draft')
                        ->get();
                    foreach ($rows as $r) {
                        $r->update(['status' => 'cancelled', 'last_error' => 'Operator bulk-cancel']);
                        if ($r->draft && $r->draft->status === 'scheduled') {
                            $r->draft->update(['status' => 'approved']);
                        }
                        $count++;
                    }
                    Notification::make()
                        ->title('Cancelled')
                        ->body("{$count} scheduled post(s) cancelled. Drafts back to 'approved'.")
                        ->warning()
                        ->send();
                }),
        ];
    }

    private function resolveWorkspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }
}
