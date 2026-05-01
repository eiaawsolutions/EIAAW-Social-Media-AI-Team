<?php

namespace App\Http\Middleware;

use App\Services\Readiness\SetupReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default landing redirect: when a logged-in user hits /agency (the dashboard),
 * if their primary brand is < 100% ready, redirect them to /agency/setup-wizard
 * so they always see their next action first. Once 100% ready, the dashboard
 * is the default.
 */
class RedirectToSetupIfIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only intercept the dashboard root; let the user navigate elsewhere freely.
        if (! $request->routeIs('filament.agency.pages.dashboard')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) return $next($request);

        $workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $workspace) return $next($request);

        $readiness = app(SetupReadiness::class)->forWorkspace($workspace);

        // No brands yet, or any brand incomplete → wizard
        if (! $readiness->hasAnyBrand || $readiness->aggregatePercent < 100) {
            return redirect('/agency/setup-wizard');
        }

        return $next($request);
    }
}
