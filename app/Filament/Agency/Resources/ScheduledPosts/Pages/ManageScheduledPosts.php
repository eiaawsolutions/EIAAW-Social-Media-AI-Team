<?php

namespace App\Filament\Agency\Resources\ScheduledPosts\Pages;

use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageScheduledPosts extends ManageRecords
{
    protected static string $resource = ScheduledPostResource::class;

    protected function getHeaderActions(): array
    {
        $workspace = $this->resolveWorkspace();
        $paused = $workspace?->publishing_paused ?? false;

        return [
            Action::make('pausePublishing')
                ->label('Pause publishing')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn () => ! $paused)
                ->requiresConfirmation()
                ->modalHeading('Pause all publishing for this workspace?')
                ->modalDescription('Every queued post will stay queued. The publish worker will skip this workspace until you click Resume. Use this for brand crisis or pre-launch staging.')
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
                        ->title('Publishing paused')
                        ->body('Queued posts will not publish until you click Resume.')
                        ->warning()
                        ->send();
                }),

            Action::make('resumePublishing')
                ->label('Resume publishing')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn () => $paused)
                ->requiresConfirmation()
                ->modalHeading('Resume publishing?')
                ->modalDescription('Queued posts whose scheduled_for has passed will publish on the next minute tick.')
                ->action(function () use ($workspace): void {
                    if (! $workspace) return;
                    $workspace->update([
                        'publishing_paused' => false,
                        'publishing_paused_at' => null,
                        'publishing_paused_reason' => null,
                    ]);
                    Notification::make()
                        ->title('Publishing resumed')
                        ->body('Queue will tick on the next minute.')
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
