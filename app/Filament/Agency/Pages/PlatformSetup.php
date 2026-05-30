<?php

namespace App\Filament\Agency\Pages;

use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Platform Setup wizard — Blotato handoff between customer and HQ.
 *
 * Why this page exists: Blotato has no native multi-tenant API
 * (see [[blotato-per-workspace-isolation]] memory). Self-serve onboarding
 * isn't possible. The customer requests setup here, HQ provisions manually
 * via `workspace:set-blotato-handle ... --notify-customer`, the customer
 * receives an email with their Blotato login, returns here and verifies.
 *
 * State machine — drives off Workspace::blotatoSetupState():
 *
 *   not_requested → button "Request Blotato setup"
 *                    → sets blotato_setup_requested_at = now(), emails HQ
 *   requested    → "We're provisioning. ETA 1 business day."
 *   credentialed → shows Blotato login URL + "Verify connection" button
 *                    → calls BlotatoClient->ping() and stamps blotato_connected_at
 *   connected    → green panel + link to /agency/platforms
 *
 * Reachable even when other panel pages are gated (allow-listed in
 * EnforceTrialOrSubscription). Customer must complete this before they can
 * touch any other product surface.
 */
class PlatformSetup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Platform setup';
    protected static ?string $title = 'Platform setup';
    protected static ?int $navigationSort = -2; // above setup-wizard
    protected string $view = 'filament.agency.pages.platform-setup';

    public ?Workspace $workspace = null;
    public string $state = 'not_requested';

    /**
     * Legacy Blotato handoff. Only the active setup surface under the
     * PUBLISH_PROVIDER=blotato rollback. When the provider is Metricool (the
     * default), this page is dormant: we hide it from nav and bounce any stale
     * bookmark / direct hit on /agency/platform-setup over to the Metricool
     * connect wizard, so customers never land on the dead Blotato screen.
     */
    public static function publishProvider(): string
    {
        return strtolower((string) config('services.publishing.provider', 'metricool')) ?: 'metricool';
    }

    public static function isActiveProvider(): bool
    {
        return self::publishProvider() === 'blotato';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::isActiveProvider();
    }

    public function mount(): \Illuminate\Http\RedirectResponse|null
    {
        if (! self::isActiveProvider()) {
            return redirect('/agency/metricool-setup');
        }

        $this->refresh();

        return null;
    }

    public function refresh(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $this->workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if ($this->workspace instanceof Workspace) {
            $this->state = $this->workspace->blotatoSetupState();
        }
    }

    /**
     * Stage 1 action: customer clicks "Request Blotato setup".
     * Marks the timestamp, notifies HQ via email. Idempotent — calling again
     * just re-notifies HQ (operator can use that as a nudge).
     */
    public function requestSetup(): void
    {
        $this->refresh();
        if (! $this->workspace) {
            return;
        }
        if ($this->state !== 'not_requested') {
            // Already requested or further along — nothing to do.
            return;
        }

        $this->workspace->forceFill([
            'blotato_setup_requested_at' => now(),
        ])->save();

        // Notify HQ via the operator email. Plain-text mail kept inline; no
        // dedicated mailable because it's HQ-only and we want the queue path
        // off the hot path for the customer's UI response.
        try {
            $operatorEmail = (string) (config('mail.cap_warning.from_address')
                ?: 'eiaawsolutions@gmail.com');
            $ws = $this->workspace;
            $body = sprintf(
                "Workspace #%d (%s, plan=%s, owner=%s) just requested a Blotato account.\n\n"
                . "Run:\n"
                . "  php artisan workspace:set-blotato-handle %d \\\n"
                . "    secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_%d \\\n"
                . "    --email=ws%d@eiaawsolutions.com \\\n"
                . "    --login-url=https://my.blotato.com/ \\\n"
                . "    --notify-customer\n\n"
                . "Steps:\n"
                . "  1. Create Blotato account at https://my.blotato.com/ with the email above.\n"
                . "  2. Set a temp password; record both in Infisical at eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_%d (the API key value, not the password).\n"
                . "  3. Run the command above. --notify-customer will email the customer the login URL + temp password.\n",
                $ws->id,
                $ws->slug,
                $ws->plan,
                optional($ws->owner)->email ?? 'unknown',
                $ws->id,
                $ws->id,
                $ws->id,
                $ws->id,
            );
            Mail::raw($body, function ($m) use ($operatorEmail, $ws) {
                $m->to('eiaawsolutions@gmail.com')
                  ->subject(sprintf('[SMT ops] Blotato provisioning request — ws#%d %s', $ws->id, $ws->slug))
                  ->from($operatorEmail, 'EIAAW SMT — Provisioning bot');
            });
        } catch (\Throwable $e) {
            // Customer's request is recorded in DB regardless. Log so HQ
            // can sweep for orphan requests if email transport flapped.
            Log::error('PlatformSetup: HQ notification email failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->refresh();

        Notification::make()
            ->title('Setup requested — our team is on it.')
            ->body('You\'ll receive an email with your Blotato login within 1 business day. We\'ll also notify you here once your account is ready.')
            ->success()
            ->send();
    }

    /**
     * Stage 3 action: customer clicks "Verify connection" after they've
     * logged in to Blotato and connected their social handles. Pings
     * Blotato with the workspace's per-tenant key; on success, stamps
     * blotato_connected_at and the state flips to 'connected'.
     */
    public function verifyConnection(): void
    {
        $this->refresh();
        if (! $this->workspace || $this->state === 'connected') {
            return;
        }
        if (in_array($this->state, ['not_requested', 'requested'], true)) {
            Notification::make()
                ->title('No Blotato credentials wired yet')
                ->body('Our team has not yet provisioned your Blotato account. Once we do, you\'ll receive an email and this page will update.')
                ->warning()
                ->send();
            return;
        }

        try {
            $client = BlotatoClient::forWorkspace($this->workspace);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not initialise Blotato client')
                ->body('This usually means the API key in our secret store is malformed. Email eiaawsolutions@gmail.com and we\'ll fix it.')
                ->danger()
                ->persistent()
                ->send();
            Log::error('PlatformSetup: BlotatoClient init failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (! $client->ping()) {
            Notification::make()
                ->title('Blotato could not be reached')
                ->body('Your API key is wired but the ping failed. Most often the API key is wrong or Blotato is rate-limiting. Email eiaawsolutions@gmail.com if this persists.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $this->workspace->forceFill(['blotato_connected_at' => now()])->save();
        $this->refresh();

        // Sanity-check what accounts the key sees — best-effort, non-fatal.
        $accountSummary = '';
        try {
            $accounts = $client->listAccounts();
            if (count($accounts) > 0) {
                $accountSummary = ' — ' . count($accounts) . ' social account(s) detected.';
            } else {
                $accountSummary = ' — no social handles connected on Blotato yet, you can do that in the Blotato dashboard.';
            }
        } catch (\Throwable $e) {
            Log::warning('PlatformSetup: listAccounts failed (non-fatal)', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
        }

        Notification::make()
            ->title('Blotato connected.')
            ->body('Your publishing account is live' . $accountSummary . ' You\'re unblocked — head to the setup wizard to continue brand onboarding.')
            ->success()
            ->send();
    }
}
