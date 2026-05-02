<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * users:purge-orphans — delete user rows whose workspaces have all been
 * removed (e.g. via workspaces:purge-unsubscribed).
 *
 * Why a separate command: workspaces:purge-unsubscribed deliberately does
 * NOT touch the users table — a single user can own multiple workspaces,
 * and we don't want a partial purge to leave a user in a half-broken
 * state. Run THIS after that one when you want a fully clean DB.
 *
 * Selection rule:
 *   - user owns no workspaces (App\Models\Workspace.owner_id = user.id)
 *   - user is not a member of any workspace (workspace_members)
 *   - user is not is_super_admin
 *
 * Dry-run by default. --force skips the confirm() prompt for non-interactive
 * use (railway ssh).
 */
class PurgeOrphanUsers extends Command
{
    protected $signature = 'users:purge-orphans
        {--apply : Actually delete the rows (default is dry-run)}
        {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Delete user rows that have no remaining workspaces (orphans from workspace purge).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $orphans = User::doesntHave('ownedWorkspaces')
            ->doesntHave('workspaces')
            ->where('is_super_admin', false)
            ->get();

        $this->info($apply ? 'PURGE MODE: changes will be committed.' : 'DRY-RUN: nothing will be deleted. Pass --apply to commit.');
        $this->line('Orphan users found: ' . $orphans->count());

        if ($orphans->isEmpty()) {
            $this->info('Nothing to purge.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'name', 'email', 'created_at'],
            $orphans->map(fn (User $u) => [
                $u->id,
                substr((string) $u->name, 0, 28),
                $u->email,
                $u->created_at?->toDateTimeString() ?? '-',
            ])->all(),
        );

        if (! $apply) {
            $this->comment('Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Delete these users?', false)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($orphans as $u) {
            $u->delete();
            $count++;
        }

        $this->info("Deleted {$count} orphan user(s).");
        return self::SUCCESS;
    }
}
