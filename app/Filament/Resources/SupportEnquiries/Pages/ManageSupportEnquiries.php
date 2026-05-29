<?php

namespace App\Filament\Resources\SupportEnquiries\Pages;

use App\Filament\Resources\SupportEnquiries\SupportEnquiryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSupportEnquiries extends ManageRecords
{
    protected static string $resource = SupportEnquiryResource::class;

    public function getSubheading(): ?string
    {
        return 'Leads from the floating "Talk to us" form — landing page and in-app. Reply, mark contacted, or close.';
    }
}
