<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * users:inspect — read-only diagnostic for a single user. Prints the user's
 * workspace bindings and their current_workspace_id resolution path.
 *
 * Useful when "Call to a member function newQueryWithoutRelationships() on null"
 * fires from a Filament Resource that depends on auth()->user()->current_workspace_id
 * — this command tells you exactly what the resource sees.
 */
class InspectUser extends Command
{
    protected $signature = 'users:inspect {--email=}';

    protected $description = 'Diagnostic: dump a user\'s workspace bindings.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        if ($email === '') {
            $this->error('--email required');
            return self::FAILURE;
        }

        $u = User::where('email', $email)->first();
        if (! $u) {
            $this->error("No user with email {$email}");
            return self::FAILURE;
        }

        $this->line("user id:                {$u->id}");
        $this->line("email:                  {$u->email}");
        $this->line("current_workspace_id:   " . ($u->current_workspace_id ?? '(NULL)'));
        $this->line("is_super_admin:         " . ($u->is_super_admin ? 'Y' : 'N'));
        $this->line("ownedWorkspaces count:  " . $u->ownedWorkspaces()->count());
        $this->line("first owned ws id:      " . ($u->ownedWorkspaces()->value('id') ?? '(NULL)'));
        $this->line("workspaces (member of): " . $u->workspaces()->count());
        $cw = $u->currentWorkspace;
        $this->line("currentWorkspace:       " . ($cw ? "id={$cw->id} slug={$cw->slug}" : '(NULL)'));

        return self::SUCCESS;
    }
}
