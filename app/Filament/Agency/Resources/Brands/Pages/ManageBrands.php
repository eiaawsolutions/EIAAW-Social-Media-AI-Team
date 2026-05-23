<?php

namespace App\Filament\Agency\Resources\Brands\Pages;

use App\Filament\Agency\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    public function getSubheading(): ?string
    {
        return 'The brands you publish for. Add one to start — each brand carries its own voice, palette, and platforms.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
