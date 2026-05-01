<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::get('/', fn () => view('landing'))->name('landing');

Route::redirect('/login', '/agency/login')->name('login');

// Filament's login route name is filament.agency.auth.login. We reference it
// from BillingController via 'agency.login.alias' for clarity at the redirect
// site; alias it here so a future Filament rename doesn't break us.
Route::redirect('/agency-login', '/agency/login')->name('agency.login.alias');

// Public signup funnel: plan-first → details form → Stripe Checkout → success
// URL handler creates the User + Workspace + Cashier subscription. No DB
// writes happen during the picker / details steps.
Route::get('/signup', [SignupController::class, 'picker'])->name('signup.picker');
Route::get('/signup/{plan}', [SignupController::class, 'selectPlan'])->name('signup.select');
Route::post('/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');
Route::get('/billing/welcome-token', [BillingController::class, 'welcomeToken'])
    ->middleware('auth')
    ->name('billing.welcome-token');
// Legacy landing-page link target — keep for any external clicks already in the wild.
Route::redirect('/register', '/signup');

// Stripe webhook — our StripeWebhookController extends Cashier's with
// idempotency + per-workspace side effects (past_due / canceled lifecycle).
// Cashier::ignoreRoutes() in AppServiceProvider disables the package's
// default route so this is the only handler.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('cashier.webhook');

Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'env' => config('app.env'),
        'time' => now()->toIso8601String(),
        'php' => PHP_VERSION,
    ]);
})->name('health');
