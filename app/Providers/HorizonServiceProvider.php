<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon dashboard is restricted to is_super_admin users.
 *
 * Default Horizon::check returns true only in local — fine for dev safety,
 * but a future env flip (or a misconfigured CI box) could expose the queue
 * dashboard. Be explicit: only EIAAW staff with is_super_admin=true reach
 * /horizon, and only when actually authenticated.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && (bool) ($user->is_super_admin ?? false);
        });
    }
}
