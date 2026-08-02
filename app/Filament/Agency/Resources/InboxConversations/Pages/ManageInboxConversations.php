<?php

namespace App\Filament\Agency\Resources\InboxConversations\Pages;

use App\Filament\Agency\Resources\InboxConversations\InboxConversationResource;
use App\Models\Brand;
use Filament\Resources\Pages\ManageRecords;

class ManageInboxConversations extends ManageRecords
{
    protected static string $resource = InboxConversationResource::class;

    public function getSubheading(): ?string
    {
        $base = 'Comments and messages people have sent your accounts. Replies are drafted in your brand voice — nothing sends until you approve it. Reply windows are set by the platforms: 24 hours for comments, 7 days for DMs.';

        $blocked = $this->permissionWarnings();

        // The single most misleading state this page can be in: a permission
        // gap makes the API return 200 with an EMPTY list, so a locked-out
        // account is pixel-identical to one nobody is messaging. An operator
        // reading "Nothing in the inbox yet" would reasonably conclude the
        // client has no engagement. Say it out loud instead.
        return $blocked === []
            ? $base
            : $base."\n\n⚠ ".implode(' · ', $blocked)
                .' — until this is fixed those inboxes will look EMPTY rather than blocked. Reconnect the account in Platform setup and grant the listed permissions.';
    }

    /**
     * Per-brand permission problems recorded by the community:ingest preflight.
     *
     * @return array<int,string>
     */
    private function permissionWarnings(): array
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id ?? $user?->ownedWorkspaces()->value('id');
        if (! $workspaceId) {
            return [];
        }

        $out = [];
        $brands = Brand::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('inbox_permissions')
            ->get(['id', 'name', 'inbox_permissions']);

        foreach ($brands as $brand) {
            foreach ((array) $brand->inbox_permissions as $key => $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $missing = implode(', ', (array) ($issue['missing_scopes'] ?? []));
                $detail = $issue['error'] ?? ($missing !== '' ? "missing {$missing}" : 'access denied');
                $out[] = "{$brand->name} · {$key}: {$detail}";
            }
        }

        return $out;
    }

    /** No create action: conversations mirror an external system. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
