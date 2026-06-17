<?php

namespace App\Http\Middleware;

use App\Support\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory legal-acceptance guard for the Agency panel. Runs in the
 * authMiddleware stack AFTER Authenticate and EnforceTrialOrSubscription, so by
 * the time it runs the user is authenticated and has cleared the
 * subscription/trial gate — we only ever show the acceptance wall to a valid,
 * paying user (never to someone the trial gate is about to eject).
 *
 * Decision tree:
 *   - no user                          → continue (auth middleware will handle it)
 *   - user accepted the CURRENT version → continue (fast path: one column compare)
 *   - request is on an allow-listed route (the acceptance page itself, auth,
 *     logout, profile, billing) → continue, so the user is never trapped
 *   - else                             → redirect to /agency/legal-acceptance
 *
 * NO super-admin or internal-workspace bypass: getting explicit, audited
 * acceptance from EVERY user — staff included — is the entire point, and it is
 * one click. The gate keys purely on the USER's accepted version, so it needs
 * no workspace lookup at all. (If staff exemption is ever wanted, it is a
 * single is_super_admin early-return at the top.)
 *
 * Bumping config('legal.version') makes every user whose stored version no
 * longer matches fall through to the redirect on their next request.
 */
class EnforceLegalAcceptance
{
    /**
     * Routes that stay reachable while a user has NOT yet accepted — the
     * acceptance page (so they can accept), auth/logout (so they can leave),
     * profile (password/email), and billing (so a paying customer isn't blocked
     * from managing their subscription). Matched via routeIs() patterns.
     */
    private const ALLOWED_ROUTE_PATTERNS = [
        'filament.agency.auth.*',
        'filament.agency.pages.legal-acceptance',
        'filament.agency.resources.profile.*',
        'filament.agency.profile',
        'filament.agency.pages.billing',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Fast path: already accepted the current version.
        if ($user->legal_accepted_version === LegalDocuments::version()) {
            return $next($request);
        }

        if ($this->isAllowedRoute($request)) {
            return $next($request);
        }

        return redirect('/agency/legal-acceptance');
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
