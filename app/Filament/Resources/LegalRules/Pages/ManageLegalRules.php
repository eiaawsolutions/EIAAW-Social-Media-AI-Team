<?php

namespace App\Filament\Resources\LegalRules\Pages;

use App\Filament\Resources\LegalRules\LegalRuleResource;
use Filament\Resources\Pages\ManageRecords;

class ManageLegalRules extends ManageRecords
{
    protected static string $resource = LegalRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'The curated legal & advertising-standards rulebook applied to every brand by industry + jurisdiction. '
            .'Disable a false-positive rule, edit a directive, or add a rule — changes take effect on the next post the AI plans, writes, or checks.';
    }
}
