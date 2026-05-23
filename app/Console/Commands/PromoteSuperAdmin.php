<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote (or demote) a user to/from super-admin. Super-admin gates
 * cross-workspace pages like /agency/agents and forces 2FA enrollment
 * on next login via AgencyPanelProvider::multiFactorAuthentication().
 *
 * Usage:
 *   php artisan user:super-admin <email>            # promote
 *   php artisan user:super-admin <email> --revoke   # demote
 *   php artisan user:super-admin <email> --status   # just show flag
 *
 * Intentionally non-interactive so it works under `railway ssh -- ...`
 * (the deploy harness has no TTY).
 */
class PromoteSuperAdmin extends Command
{
    protected $signature = 'user:super-admin
                            {email : User email to act on}
                            {--revoke : Demote (set is_super_admin = false)}
                            {--status : Show current flag, no changes}';

    protected $description = 'Promote or demote a user as super-admin.';

    public function handle(): int
    {
        $email = trim($this->argument('email'));
        if ($email === '') {
            $this->error('Email is required.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user found with email: {$email}");
            return self::FAILURE;
        }

        $this->line(sprintf(
            'User #%d %s — is_super_admin currently %s',
            $user->id,
            $user->email,
            $user->is_super_admin ? 'YES' : 'NO',
        ));

        if ($this->option('status')) {
            return self::SUCCESS;
        }

        $target = ! $this->option('revoke');
        if ((bool) $user->is_super_admin === $target) {
            $this->info('No change needed.');
            return self::SUCCESS;
        }

        $user->is_super_admin = $target;
        $user->save();

        $this->info(sprintf(
            '%s — is_super_admin is now %s',
            $target ? 'Promoted' : 'Revoked',
            $target ? 'YES' : 'NO',
        ));
        $this->line('Note: super-admins are forced through 2FA setup on next login. Have an authenticator app ready.');

        return self::SUCCESS;
    }
}
