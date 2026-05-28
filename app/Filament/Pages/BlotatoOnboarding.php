<?php

namespace App\Filament\Pages;

use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

/**
 * HQ-only onboarding console for the per-workspace Blotato handoff.
 *
 * This is the page you keep open next to the terminal. It does NOT replace
 * the manual steps (creating the Blotato account, putting the API key in
 * Infisical) — Blotato has no provisioning API and the EIAAW Deploy Contract
 * forbids raw secrets in the model's token stream. What it DOES do is remove
 * every hand-typed, error-prone bit AFTER the account exists:
 *
 *   - lists workspaces that asked for setup but aren't connected yet
 *   - generates the exact `workspace:set-blotato-handle` command per workspace
 *     (correct ID, correct secret:// path, correct email, --notify-customer)
 *   - lets you verify a connection from the browser instead of SSHing in
 *
 * Lives in the Admin panel (super-admin only via User::canAccessPanel).
 */
class BlotatoOnboarding extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';
    protected static ?string $navigationLabel = 'Blotato onboarding';
    protected static ?string $title = 'Blotato onboarding';
    protected static \UnitEnum|string|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'blotato-onboarding';
    protected string $view = 'filament.pages.blotato-onboarding';

    /**
     * The Infisical project segment + env that per-workspace Blotato secrets
     * live under. Used only to BUILD the command string shown on screen —
     * no secret value is ever read here.
     */
    public const INFISICAL_PROJECT = 'eiaaw-smt-prod';
    public const INFISICAL_ENV = 'prod';
    public const BLOTATO_LOGIN_URL = 'https://my.blotato.com/';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function getSubheading(): ?string
    {
        return 'The keep-it-next-to-the-terminal checklist. Account creation is manual (Blotato has no API for it); everything after is generated for you below.';
    }

    /**
     * Workspaces grouped by where they are in the Blotato handoff. Recomputed
     * on every render — these are operator counts, not hot-path queries, so a
     * live read is fine (and we WANT it fresh after running a command).
     *
     * @return array{queue: array<int, array<string,mixed>>, connected_count: int}
     */
    public function getBoard(): array
    {
        // Customer-facing workspaces only — HQ/internal workspaces use their
        // own Blotato account but never go through the request flow.
        $workspaces = Workspace::query()
            ->where('plan', '!=', 'eiaaw_internal')
            ->orderByRaw('blotato_setup_requested_at IS NULL')      // requested first
            ->orderBy('blotato_setup_requested_at')
            ->get();

        $queue = [];
        $connected = 0;

        foreach ($workspaces as $ws) {
            $state = $ws->blotatoSetupState();
            if ($state === 'connected') {
                $connected++;
                continue;
            }
            // Skip never-requested workspaces — they're not waiting on HQ.
            if ($state === 'not_requested') {
                continue;
            }

            $queue[] = [
                'id'             => $ws->id,
                'name'           => $ws->name,
                'slug'           => $ws->slug,
                'plan'           => $ws->plan,
                'owner_email'    => optional($ws->owner)->email,
                'state'          => $state,
                'requested_at'   => optional($ws->blotato_setup_requested_at)->diffForHumans(),
                'credentialed_at'=> optional($ws->blotato_credentials_sent_at)->diffForHumans(),
                'secret_path'    => $this->secretPath($ws->id),
                'blotato_email'  => $ws->blotato_account_email ?: ('ws' . $ws->id . '@eiaawsolutions.com'),
                'command'        => $this->buildCommand($ws),
            ];
        }

        return ['queue' => $queue, 'connected_count' => $connected];
    }

    /** Suggested Blotato login email for a workspace (operator can override). */
    public function blotatoEmailFor(int $workspaceId): string
    {
        return 'ws' . $workspaceId . '@eiaawsolutions.com';
    }

    /** The Infisical handle path for a workspace's Blotato API key. */
    public function secretPath(int $workspaceId): string
    {
        return sprintf(
            'secret://%s/%s/BLOTATO_API_KEY_WS_%d',
            self::INFISICAL_PROJECT,
            self::INFISICAL_ENV,
            $workspaceId,
        );
    }

    /**
     * The exact, copy-paste-ready command. Note: --temp-password is a
     * placeholder the operator fills in (we never know or store the Blotato
     * account password). Everything else is correct for this workspace.
     */
    public function buildCommand(Workspace $ws): string
    {
        return sprintf(
            "php artisan workspace:set-blotato-handle %d \\\n  %s \\\n  --email=%s \\\n  --login-url=%s \\\n  --temp-password='PASTE_TEMP_PASSWORD_HERE' \\\n  --notify-customer",
            $ws->id,
            $this->secretPath($ws->id),
            $ws->blotato_account_email ?: $this->blotatoEmailFor($ws->id),
            self::BLOTATO_LOGIN_URL,
        );
    }

    /**
     * Verify a workspace's Blotato connection straight from the browser —
     * same ping the artisan command runs, so you can confirm a handoff
     * worked without opening a shell. Stamps blotato_connected_at on success.
     */
    public function verify(int $workspaceId): void
    {
        $ws = Workspace::find($workspaceId);
        if (! $ws) {
            Notification::make()->title('Workspace not found')->danger()->send();
            return;
        }

        if ($ws->needsBlotatoSetup()) {
            Notification::make()
                ->title('No handle wired yet')
                ->body('Run the generated command first — there\'s no Blotato key on this workspace to verify.')
                ->warning()
                ->send();
            return;
        }

        try {
            $client = BlotatoClient::forWorkspace($ws);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Client init failed')
                ->body('The secret:// handle is set but the value could not be resolved from Infisical. Check the handle path + machine identity scoping.')
                ->danger()
                ->persistent()
                ->send();
            Log::error('BlotatoOnboarding: client init failed', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! $client->ping()) {
            Notification::make()
                ->title('Ping failed')
                ->body('Handle resolves but Blotato rejected the key. Most likely the Infisical value is wrong or stale.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $ws->forceFill(['blotato_connected_at' => now()])->save();

        $accountNote = '';
        try {
            $accounts = $client->listAccounts();
            $accountNote = ' Sees ' . count($accounts) . ' connected social account(s).';
        } catch (\Throwable $e) {
            // non-fatal
        }

        Notification::make()
            ->title('Connected — ws#' . $workspaceId . ' verified')
            ->body('blotato_connected_at stamped. The customer\'s panel is now unblocked.' . $accountNote)
            ->success()
            ->send();
    }
}
