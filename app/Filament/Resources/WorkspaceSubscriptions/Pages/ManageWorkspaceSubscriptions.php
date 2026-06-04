<?php

namespace App\Filament\Resources\WorkspaceSubscriptions\Pages;

use App\Filament\Resources\WorkspaceSubscriptions\WorkspaceSubscriptionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkspaceSubscriptions extends ManageRecords
{
    protected static string $resource = WorkspaceSubscriptionResource::class;

    public function getSubheading(): ?string
    {
        return 'Every customer workspace and its subscription lifecycle. Cancel at period end, cancel immediately (offboarding only), or reactivate a pending cancellation — each action is recorded in the audit log. EIAAW internal workspaces are excluded (never billed).';
    }
}
