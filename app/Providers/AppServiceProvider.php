<?php

namespace App\Providers;

use App\Listeners\EnsureUserHasWorkspace;
use App\Models\Workspace;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Login::class, EnsureUserHasWorkspace::class);

        // Cashier's customer is the Workspace, not the User. EIAAW SMT
        // bills per workspace (flat brand-based pricing), so Stripe
        // customers, subscriptions, and invoices all belong to a workspace.
        Cashier::useCustomerModel(Workspace::class);
        // We register our own extended webhook controller in routes/web.php
        // (StripeWebhookController adds idempotency + workspace side effects),
        // so disable Cashier's default /stripe/webhook route.
        Cashier::ignoreRoutes();
    }
}
