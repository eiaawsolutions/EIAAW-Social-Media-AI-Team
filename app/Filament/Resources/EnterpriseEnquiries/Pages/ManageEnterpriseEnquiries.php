<?php

namespace App\Filament\Resources\EnterpriseEnquiries\Pages;

use App\Filament\Resources\EnterpriseEnquiries\EnterpriseEnquiryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageEnterpriseEnquiries extends ManageRecords
{
    protected static string $resource = EnterpriseEnquiryResource::class;

    public function getSubheading(): ?string
    {
        return 'Leads from the dedicated /enterprise "Talk to us" page. Reply, qualify, or close. After a deal closes, provision the bespoke workspace manually (plan=enterprise + a custom cap snapshot).';
    }
}
