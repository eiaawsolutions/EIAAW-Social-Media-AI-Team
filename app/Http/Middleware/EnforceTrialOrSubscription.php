<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trial-expiry guard. Runs after Filament's Authenticate middleware in the
 * Agency panel.
 *
 * Decision tree:
 *   - super admin                  → bypass (EIAAW staff)
 *   - no current workspace         → bypass (Filament/setup will redirect)
 *   - workspace plan=eiaaw_internal → bypass (HQ workspaces never billed)
 *   - workspace has NO Cashier subscription record → log out + redirect to /signup
 *     (orphan account — somehow created without going through Stripe Checkout;
 *      strict invariant: every paying workspace must have a subscriptions row)
 *   - workspace hasActiveAccess()  → continue (trialing in-window | active | past_due in 3d grace)
 *   - else                         → redirect to /agency/billing (paywall),
 *                                    unless on an allowed billing/profile/logout route
 *
 * The paywall is sticky: an expired-trial workspace cannot reach any
 * resource, dashboard, or setup wizard route until they subscribe.
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
        foreach (self::ALLOWED_ROUTE_PATTERNS as $pattern) {
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
