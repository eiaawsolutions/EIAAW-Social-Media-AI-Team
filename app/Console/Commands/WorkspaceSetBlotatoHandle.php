<?php

namespace App\Console\Commands;

use App\Mail\BlotatoAccountReady;
use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * workspace:set-blotato-handle — operator-only command to wire a workspace's
 * Blotato API key handle.
 *
 * Why a command instead of a Filament field: per the EIAAW Deploy Contract,
 * raw secret values never enter the model's token stream and never live in
 * the DB. The handle is a `secret://` reference; the operator first creates
 * the Infisical secret manually (Claude does not call set_secret), THEN
 * runs this command to bind the handle to the workspace. A Filament text
 * field would invite pasting raw values by mistake.
 *
 * Usage:
 *   php artisan workspace:set-blotato-handle 42 \
 *     secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_42 \
 *     --email=ws42@eiaawsolutions.com
 *
 *   php artisan workspace:set-blotato-handle 42 --verify-only
 *     # Pings Blotato with the existing handle and records the timestamp.
 *
 *   php artisan workspace:set-blotato-handle 42 --clear
 *     # Removes the handle (disconnects the workspace from Blotato).
 */
class WorkspaceSetBlotatoHandle extends Command
{
    protected $signature = 'workspace:set-blotato-handle
                            {workspace : Workspace ID}
                            {handle? : secret:// handle pointing at the Infisical secret}
                            {--email= : Blotato account email for operator reference}
                            {--login-url= : Blotato login URL to surface to the customer (default: https://my.blotato.com/)}
                            {--temp-password= : Temp password to email to the customer (only used with --notify-customer)}
                            {--notify-customer : Email the customer the Blotato login URL + (optional) temp password}
                            {--verify-only : Just ping with the existing handle, don\'t change it}
                            {--clear : Remove the handle (disconnect)}';

    protected $description = 'Bind a per-workspace Blotato API key handle. Operator-only; raw values never accepted.';

    public function handle(): int
    {
        $workspaceId = (int) $this->argument('workspace');
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            $this->error("Workspace #{$workspaceId} not found.");
            return self::FAILURE;
        }

        $this->info("Workspace #{$workspace->id} ({$workspace->slug}) — current handle: " . ($workspace->blotato_api_key_handle ?: '(none)'));

        if ($this->option('clear')) {
            $workspace->forceFill([
                'blotato_api_key_handle' => null,
                'blotato_account_email' => null,
                'blotato_connected_at' => null,
                'blotato_login_url' => null,
                'blotato_credentials_sent_at' => null,
                // Intentionally NOT clearing blotato_setup_requested_at —
                // if HQ disconnects and re-provisions, the original request
                // timestamp is still the user-visible "you asked on date X".
            ])->save();
            $this->info('Cleared. This workspace can no longer publish or sync via Blotato until a new handle is set.');
            return self::SUCCESS;
        }

        if ($this->option('verify-only')) {
            if ($workspace->needsBlotatoSetup()) {
                $this->error('No handle set on this workspace — nothing to verify.');
                return self::FAILURE;
            }
            return $this->verifyAndRecord($workspace) ? self::SUCCESS : self::FAILURE;
        }

        $handle = (string) $this->argument('handle');
        if ($handle === '') {
            $this->error('handle argument required. Use --clear to remove, or --verify-only to re-test.');
            return self::FAILURE;
        }

        // Hard refusal: anything that doesn't look like a secret:// handle.
        // Raw API keys (starting `blt_...`) would be caught by this check
        // and rejected — they belong in Infisical, not the DB.
        if (! str_starts_with($handle, 'secret://')) {
            $this->error('handle must start with `secret://`. Raw API keys are forbidden — provision the secret in Infisical first, then pass the handle here.');
            return self::FAILURE;
        }

        $loginUrl = (string) ($this->option('login-url') ?: $workspace->blotato_login_url ?: 'https://my.blotato.com/');

        $workspace->forceFill([
            'blotato_api_key_handle' => $handle,
            'blotato_account_email' => $this->option('email') ?: $workspace->blotato_account_email,
            'blotato_login_url' => $loginUrl,
            'blotato_connected_at' => null, // re-verify after change
        ])->save();
        $this->info('Handle set. Verifying with Blotato ping…');

        $verified = $this->verifyAndRecord($workspace);

        if ($verified && $this->option('notify-customer')) {
            $this->notifyCustomer($workspace, $loginUrl);
        } elseif (! $verified && $this->option('notify-customer')) {
            $this->warn('--notify-customer skipped because verification failed. Fix the handle and re-run with --verify-only --notify-customer, or just re-run this command.');
        }

        return $verified ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Send the customer the BlotatoAccountReady email. Stamps
     * blotato_credentials_sent_at so the PlatformSetup page flips to the
     * "credentialed, awaiting verify" state.
     *
     * --temp-password is optional. If omitted, the email tells the customer
     * to use Blotato's "Forgot password" flow instead — safer when the
     * operator wants to avoid putting a password in email at all.
     */
    private function notifyCustomer(Workspace $workspace, string $loginUrl): void
    {
        $owner = $workspace->owner;
        if (! $owner || ! $owner->email) {
            $this->error('--notify-customer set but workspace has no owner email. Cannot send.');
            return;
        }

        $blotatoEmail = (string) ($workspace->blotato_account_email ?: $owner->email);
        $tempPassword = $this->option('temp-password') ?: null;
        if ($tempPassword !== null) {
            $tempPassword = (string) $tempPassword;
        }

        try {
            Mail::to($owner->email)->queue(new BlotatoAccountReady(
                workspace: $workspace,
                loginUrl: $loginUrl,
                blotatoAccountEmail: $blotatoEmail,
                tempPassword: $tempPassword,
            ));
            $workspace->forceFill(['blotato_credentials_sent_at' => now()])->save();
            $this->info('✓ Customer notified at ' . $owner->email . ' (Blotato email: ' . $blotatoEmail . ').');
            if ($tempPassword === null) {
                $this->line('  (No --temp-password supplied — email points the customer at Blotato\'s "Forgot password" flow.)');
            }
        } catch (\Throwable $e) {
            $this->error('Failed to queue customer notification: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the handle via InfisicalResolver, build a client, ping. On
     * success stamp blotato_connected_at; on failure leave it null and
     * surface the error so the operator can fix Infisical config.
     */
    private function verifyAndRecord(Workspace $workspace): bool
    {
        try {
            $client = BlotatoClient::forWorkspace($workspace);
        } catch (\Throwable $e) {
            $this->error('Client init failed: ' . $e->getMessage());
            return false;
        }

        if (! $client->ping()) {
            $this->error('Blotato ping failed. Verify the secret value in Infisical and that the key starts with `blt_`.');
            return false;
        }

        $workspace->forceFill(['blotato_connected_at' => now()])->save();
        $this->info('✓ Ping OK. blotato_connected_at recorded.');

        // Bonus: list a few accounts so the operator can sanity-check they're
        // looking at the right Blotato tenant (catches "I wired the wrong
        // workspace's secret" mistakes early).
        try {
            $accounts = $client->listAccounts();
            $this->info('Accounts visible from this key (' . count($accounts) . '):');
            foreach (array_slice($accounts, 0, 5) as $a) {
                $this->line(sprintf('  [%s] %s — %s', $a['platform'] ?? '?', $a['username'] ?? '?', $a['fullname'] ?? '?'));
            }
            if (count($accounts) > 5) {
                $this->line('  …and ' . (count($accounts) - 5) . ' more');
            }
        } catch (\Throwable $e) {
            $this->warn('Could not list accounts (non-fatal): ' . $e->getMessage());
        }

        return true;
    }
}
