<?php

namespace App\Filament\Agency\Resources\ScheduledPosts\Pages;

use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use Filament\Resources\Pages\ManageRecords;

class ManageScheduledPosts extends ManageRecords
{
    protected static string $resource = ScheduledPostResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
