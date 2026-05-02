<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * users:set-password — operator escape hatch for setting a user's password
 * directly. Used when password-reset email delivery is misconfigured (Mailgun
 * not set up, SMTP failing, etc.) and an existing user can't get into their
 * account, OR for the founder bootstrap after billing:reconcile-session
 * provisions a User without going through the welcome-email path.
 *
 * Logs every use to subscription_events-equivalent audit trail (currently
 * just laravel.log) since this is a privileged action. Intentionally
 * console-only — no HTTP surface.
 *
 * Usage:
 *   php artisan users:set-password --email=eiaawsolutions@gmail.com --password='XXX'
 */
class SetUserPassword extends Command
{
    protected $signature = 'users:set-password
        {--email= : Email of the user to update}
        {--password= : New plaintext password (will be hashed)}';

    protected $description = 'Set a users password directly. Operator escape hatch when email delivery is broken.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');

        if ($email === '' || $password === '') {
            $this->error('Both --email and --password are required.');
            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user with email {$email}.");
            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        \Illuminate\Support\Facades\Log::warning('users:set-password used', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $this->info("Password updated for {$user->email} (user id {$user->id}).");
        $this->line('They can now log in at /agency/login. Recommend changing it from inside the panel.');

        return self::SUCCESS;
    }
}
