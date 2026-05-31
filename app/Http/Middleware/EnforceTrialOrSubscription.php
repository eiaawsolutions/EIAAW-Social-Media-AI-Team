<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trial-expiry + platform-setup guard. Runs after Filament's Authenticate
 * middleware in the Agency panel.
 *
 * Decision tree (in order):
 *   - super admin                  → bypass (EIAAW staff)
 *   - no current workspace         → bypass (Filament/setup will redirect)
 *   - workspace plan=eiaaw_internal → bypass (HQ workspaces never billed)
 *   - workspace has NO Cashier subscription record → log out + redirect to /signup
 *   - workspace has expired access → redirect to /agency/trial-expired
 *   - workspace lacks Blotato connection → redirect to /agency/platform-setup
 *     (unless they're on platform-setup / billing / profile / auth — those
 *      routes remain available so the user can complete or pay or escape)
 *   - else → continue
 *
 * The Blotato gate is added because every workspace requires a paid Blotato
 * account that HQ provisions manually (see [[blotato-per-workspace-isolation]]).
 * Without it, publishing is impossible — gating the panel forces the customer
 * to walk through PlatformSetup before they can configure brands they can't use.
 */
class EnforceTrialOrSubscription
{
    /**
     * Routes that remain accessible even when a trial has expired —
     * billing (so they can pay), profile (so they can change password /
     * email), and Filament's auth/logout endpoints.
     *
     * Matched against `routeIs()` patterns.
     */
    private const ALLOWED_ROUTE_PATTERNS = [
        'filament.agency.auth.*',
        'filament.agency.pages.billing',
        'filament.agency.pages.trial-expired',
        'filament.agency.resources.profile.*',
        'filament.agency.profile',
    ];

    /**
     * Additional routes accessible while the platform-connection gate is
     * closed. Superset of ALLOWED_ROUTE_PATTERNS — the customer needs to reach
     * a setup page (Metricool connect wizard OR the legacy Blotato one) to
     * advance, and may still want billing / password change. Both setup pages
     * are listed so the gate works under either PUBLISH_PROVIDER.
     *
     * The Setup Wizard is allow-listed too: a fresh workspace has NO brands,
     * so hasAnyMetricoolConnectedBrand() is false and the gate is closed — yet
     * the Setup Wizard is the ONLY place to create that first brand. Without it
     * here, the metricool-setup page's "Go to setup wizard" CTA bounces the
     * customer straight back to metricool-setup (redirect-to-self loop → the
     * button appears to do nothing). Active-access is already verified before
     * this gate runs, so exposing the wizard to paying customers is safe.
     */
    private const SETUP_ALLOWED_ROUTE_PATTERNS = [
        'filament.agency.auth.*',
        'filament.agency.pages.billing',
        'filament.agency.pages.metricool-setup',
        'filament.agency.pages.platform-setup',
        'filament.agency.pages.setup-wizard',
        'filament.agency.resources.profile.*',
        'filament.agency.profile',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_super_admin) {
            return $next($request);
        }

        $workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $workspace) {
            return $next($request);
        }

        // EIAAW HQ workspaces are never billed; skip every check.
        if ($workspace->plan === 'eiaaw_internal') {
            return $next($request);
        }

        // Strict invariant: every non-internal workspace MUST have a Cashier
        // subscriptions row. The only path to creating one is BillingController::success
        // which runs after a completed Stripe Checkout. If a workspace exists without
        // one, it's an orphan from before the strict-checkout invariant landed —
        // log the user out and bounce them to /signup so they can do it properly.
        if (! $workspace->subscriptions()->exists()) {
            \Illuminate\Support\Facades\Log::warning('orphan workspace without Cashier subscription — forcing re-signup', [
                'workspace_id' => $workspace->id,
                'workspace_slug' => $workspace->slug,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect('/signup')->with(
                'error',
                'Your account is missing a subscription record. Please complete signup again — your previous workspace data is preserved.',
            );
        }

        if ($workspace->hasActiveAccess()) {
            // Refresh status if trial just ticked over (cheap — only writes
            // when state actually changed).
            $this->reconcileTrialStatus($workspace);

            // Second gate: platform connection. Provider-aware (PUBLISH_PROVIDER):
            //   metricool → workspace must have ≥1 Metricool-connected brand;
            //               redirect to the Metricool connect wizard.
            //   blotato   → legacy per-workspace Blotato connection (rollback).
            // Only enforced AFTER active-access is confirmed — we never bounce a
            // paying-but-unwired customer to trial-expired; we send them to the
            // setup wizard so they can move forward.
            $provider = strtolower((string) config('services.publishing.provider', 'metricool')) ?: 'metricool';
            if ($provider === 'metricool') {
                if (! $workspace->hasAnyMetricoolConnectedBrand()
                    && ! $this->isAllowedRouteFor($request, self::SETUP_ALLOWED_ROUTE_PATTERNS)) {
                    return redirect('/agency/metricool-setup');
                }
            } else {
                if (! $workspace->hasBlotatoConnected()
                    && ! $this->isAllowedRouteFor($request, self::SETUP_ALLOWED_ROUTE_PATTERNS)) {
                    return redirect('/agency/platform-setup');
                }
            }

            return $next($request);
        }

        $this->reconcileTrialStatus($workspace);

        if ($this->isAllowedRoute($request)) {
            return $next($request);
        }

        return redirect('/agency/trial-expired');
    }

    /**
     * If a trialing workspace's clock has run out, flip its
     * subscription_status to 'none' so the billing UI shows the right
     * messaging. We do NOT zero out trial_ends_at — keep it as the historical
     * record of when trial ended.
     */
    private function reconcileTrialStatus(\App\Models\Workspace $workspace): void
    {
        if ($workspace->subscription_status === 'trialing'
            && $workspace->trial_ends_at !== null
            && $workspace->trial_ends_at->isPast()
        ) {
            $workspace->update(['subscription_status' => 'none']);
        }
    }

    private function isAllowedRoute(Request $request): bool
    {
        return $this->isAllowedRouteFor($request, self::ALLOWED_ROUTE_PATTERNS);
    }

    /**
     * @param array<int, string> $patterns
     */
    private function isAllowedRouteFor(Request $request, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($request->routeIs($pattern)) {
                return true;
            }
        }

        // Logout endpoint hit via POST — never block.
        if ($request->is('agency/logout')) {
            return true;
        }

        return false;
    }
}
