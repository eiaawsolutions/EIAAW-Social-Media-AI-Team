<?php

namespace App\Filament\Agency\Resources\CalendarEntries\Pages;

use App\Filament\Agency\Resources\CalendarEntries\CalendarEntryResource;
use App\Jobs\DraftCalendarEntry;
use App\Models\CalendarEntry;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageCalendarEntries extends ManageRecords
{
    protected static string $resource = CalendarEntryResource::class;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('draftAll')
                ->label('Draft all undrafted entries')
                ->icon('heroicon-o-bolt')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Draft every calendar entry that has no draft yet?')
                ->modalDescription(
                    'Fans out one background job per (entry, platform) pair. Each job runs Writer + Designer + Compliance.'
                    . ' Cost: ~$0.04 per image (FAL flux-pro/v1.1) plus Writer LLM cost.'
                    . ' Daily image budget cap is enforced per workspace.'
                )
                ->action(function (): void {
                    $brand = $this->resolveCurrentBrand();
                    if (! $brand) {
                        Notification::make()->title('No brand in workspace')->danger()->send();
                        return;
                    }

                    $entries = CalendarEntry::where('brand_id', $brand->id)
                        ->orderBy('scheduled_date')
                        ->get();

                    $dispatched = 0;
                    $skipped = 0;
                    foreach ($entries as $entry) {
                        $platforms = is_array($entry->platforms) ? $entry->platforms : [];
                        foreach ($platforms as $platform) {
                            $hasDraft = $entry->drafts()
                                ->where('platform', $platform)
                                ->whereNotIn('status', ['rejected'])
                                ->exists();
                            if ($hasDraft) {
                                $skipped++;
                                continue;
                            }
                            DraftCalendarEntry::dispatch($entry->id, $platform)
                                ->onQueue('drafting');
                            $dispatched++;
                        }
                    }

                    Notification::make()
                        ->title('Drafting fanned out')
                        ->body(sprintf(
                            'Dispatched %d job(s); skipped %d already-drafted (entry, platform) pair(s). Watch /agency/drafts as they land.',
                            $dispatched, $skipped,
                        ))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function resolveCurrentBrand(): ?\App\Models\Brand
    {
        $user = auth()->user();
        if (! $user) return null;

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) return null;

        return \App\Models\Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
    }
}
