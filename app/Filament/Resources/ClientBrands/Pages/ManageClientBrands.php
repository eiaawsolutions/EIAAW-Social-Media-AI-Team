<?php

namespace App\Filament\Resources\ClientBrands\Pages;

use App\Filament\Resources\ClientBrands\ClientBrandResource;
use Filament\Resources\Pages\ManageRecords;

class ManageClientBrands extends ManageRecords
{
    protected static string $resource = ClientBrandResource::class;

    public function getSubheading(): ?string
    {
        return 'Every client workspace\'s brands, labelled by owner. Edit a brand, set its Metricool routing space, or archive/restore — all in place, scoped to the right tenant. Your own HQ brand is administered from your Agency account.';
    }
}
