<?php

namespace App\Filament\Agency\Resources\CalendarEntries\Pages;

use App\Filament\Agency\Resources\CalendarEntries\CalendarEntryResource;
use App\Jobs\DraftCalendarEntry;
use App\Models\CalendarEntry;
use App\Services\Brands\BrandScope;
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

    public function getSubheading(): ?string
    {
        $scope = app(BrandScope::class);

        $base = 'The planned posting cadence per brand. Generate it once, then draft against it.';

        return $scope->shouldRender()
            ? $base.' Showing '.$scope->description().' — change with the brand picker in the top bar.'
            : $base;
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
                // Name the brands explicitly — this button dispatches paid AI
                // work, so the operator must see its blast radius before
                // confirming rather than discovering it from the result toast.
                ->modalDescription(fn (): string => sprintf(
                    'Drafts entries for %s. Fans out one background job per (entry, platform) pair. Each job runs Writer + Designer + Compliance. A daily generation cap is enforced per workspace.',
                    app(BrandScope::class)->description(),
                ))
                ->action(function (): void {
                    // Every brand in the CURRENT SCOPE, not just the workspace's
                    // first brand. The old resolveCurrentBrand() took
                    // orderBy('id')->first(), so on Studio / Agency / Enterprise
                    // this button could only ever draft brand #1's calendar —
                    // every other brand's entries were unreachable from the UI.
                    $brandIds = app(BrandScope::class)->brandIds();
                    if ($brandIds === []) {
                        Notification::make()->title('No brand in workspace')->danger()->send();
                        return;
                    }

                    $entries = CalendarEntry::whereIn('brand_id', $brandIds)
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
                            'Dispatched %d job(s) across %s; skipped %d already-drafted (entry, platform) pair(s). Watch /agency/drafts as they land.',
                            $dispatched,
                            app(BrandScope::class)->description(),
                            $skipped,
                        ))
                        ->success()
                        ->send();
                }),
        ];
    }
}
