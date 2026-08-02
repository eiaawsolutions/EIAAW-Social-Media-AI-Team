<?php

namespace App\Filament\Agency\Resources\InboxConversations\Pages;

use App\Filament\Agency\Resources\InboxConversations\InboxConversationResource;
use Filament\Resources\Pages\ManageRecords;

class ManageInboxConversations extends ManageRecords
{
    protected static string $resource = InboxConversationResource::class;

    public function getSubheading(): ?string
    {
        return 'Comments and messages people have sent your accounts. Replies are drafted in your brand voice — nothing sends until you approve it. Reply windows are set by the platforms: 24 hours for comments, 7 days for DMs.';
    }

    /** No create action: conversations mirror an external system. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
