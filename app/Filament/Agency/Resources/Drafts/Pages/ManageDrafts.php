<?php

namespace App\Filament\Agency\Resources\Drafts\Pages;

use App\Filament\Agency\Resources\Drafts\DraftResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDrafts extends ManageRecords
{
    protected static string $resource = DraftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
