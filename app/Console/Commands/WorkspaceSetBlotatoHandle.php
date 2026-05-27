<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Console\Command;

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

        $workspace->forceFill([
            'blotato_api_key_handle' => $handle,
            'blotato_account_email' => $this->option('email') ?: $workspace->blotato_account_email,
            'blotato_connected_at' => null, // re-verify after change
        ])->save();
        $this->info('Handle set. Verifying with Blotato ping…');

        return $this->verifyAndRecord($workspace) ? self::SUCCESS : self::FAILURE;
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
