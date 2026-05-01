<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On every login, make sure the user has a workspace they can operate in.
 *
 * If the user has none, we auto-create a "Personal" workspace and seed them
 * as the owner. This is the simplicity move — they should never see "you
 * have no workspace, contact support" or have to fill out a workspace form
 * before creating a brand.
 */
class EnsureUserHasWorkspace
{
    public function handle(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;
        if (! $user instanceof User) return;

        // Quick path — already has a workspace
        if ($user->workspaces()->exists() || $user->ownedWorkspaces()->exists()) {
            // Refresh current_workspace_id if missing
            if (! $user->current_workspace_id) {
                $first = $user->ownedWorkspaces()->first() ?? $user->workspaces()->first();
                if ($first) {
                    $user->update(['current_workspace_id' => $first->id]);
                }
            }
            $user->update(['last_login_at' => now()]);
            return;
        }

        // No workspace yet — create one
        DB::transaction(function () use ($user) {
            $baseSlug = Str::slug(Str::beforeLast($user->email, '@')) ?: 'workspace';
            $slug = $baseSlug;
            $i = 1;
            while (Workspace::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.++$i;
            }

            $workspace = Workspace::create([
                'slug' => $slug,
                'name' => $user->name ? $user->name."'s workspace" : 'My workspace',
                'owner_id' => $user->id,
                'type' => 'agency',
                'plan' => 'solo',
                'trial_ends_at' => now()->addDays(14),
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);

            $user->update([
                'current_workspace_id' => $workspace->id,
                'last_login_at' => now(),
            ]);
        });
    }
}
