<?php

namespace App\Filament\Agency\Resources\CalendarEntries\Pages;

use App\Filament\Agency\Resources\CalendarEntries\CalendarEntryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCalendarEntries extends ManageRecords
{
    protected static string $resource = CalendarEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
