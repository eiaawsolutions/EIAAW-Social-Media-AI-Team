<?php

namespace App\Providers;

use App\Listeners\EnsureUserHasWorkspace;
use App\Models\Workspace;
use App\Services\Metricool\AccountGrowthService;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AccountGrowthService takes a nullable MetricoolClient (null = the
        // shared Metricool token isn't wired in this env, so the dashboard
        // shows its "not configured" state instead of erroring). The container
        // can't autowire a nullable readonly dependency, so resolve it from
        // config here — mirrors how MetricsProviderRouter pulls the client.
        $this->app->bind(
            AccountGrowthService::class,
            fn () => new AccountGrowthService(MetricoolClient::fromConfig()),
        );

        // Pin Filament's password-reset notification to the DELIVERABLE mailer.
        // Filament's RequestPasswordReset page resolves
        // Filament\Auth\Notifications\ResetPassword from the container and calls
        // $user->notify() directly (bypassing User::sendPasswordResetNotification),
        // and that notification rides the DEFAULT mailer — which is `log` on prod,
        // so reset links were silently dropped (the Bear Hug lockout). Binding the
        // base id to our PinnedResetPassword subclass makes every Filament reset
        // email ride Resend instead. The $url parameter Filament passes is honored
        // (PinnedResetPassword extends Filament's notification, which sets $url).
        $this->app->bind(
            \Filament\Auth\Notifications\ResetPassword::class,
            fn ($app, array $params) => new \App\Notifications\PinnedResetPassword(...$params),
        );
    }

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
