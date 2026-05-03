<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CspReportController;
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
//
// Rate limits guard against scripted account-enumeration / Stripe-customer
// flooding. Picker GETs are forgiving (60/min); checkout POST is tight
// (10/min/IP) — anything higher is almost certainly automated abuse.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/signup', [SignupController::class, 'picker'])->name('signup.picker');
    Route::get('/signup/{plan}', [SignupController::class, 'selectPlan'])->name('signup.select');
    Route::get('/billing/success', [BillingController::class, 'success'])->name('billing.success');
});

Route::post('/billing/checkout/{plan}', [BillingController::class, 'checkout'])
    ->middleware('throttle:10,1')
    ->name('billing.checkout');

Route::get('/billing/welcome-token', [BillingController::class, 'welcomeToken'])
    ->middleware(['auth', 'throttle:30,1'])
    ->name('billing.welcome-token');
// Legacy landing-page link target — keep for any external clicks already in the wild.
Route::redirect('/register', '/signup');

// Stripe webhook — our StripeWebhookController extends Cashier's with
// idempotency + per-workspace side effects (past_due / canceled lifecycle).
// Cashier::ignoreRoutes() in AppServiceProvider disables the package's
// default route so this is the only handler.
//
// No throttle: Stripe burst-delivers up to ~25 req/sec during webhook
// catch-up after an outage and we MUST process them all (idempotency table
// dedupes). The VerifyWebhookSignature middleware rejects any request not
// signed with STRIPE_WEBHOOK_SECRET — that's the abuse gate.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('cashier.webhook');

// Health endpoint — minimal liveness ping. Do NOT leak environment, PHP
// version, or other reconnaissance signals. Railway / load-balancer probes
// only need a 200 + small body to consider the app healthy.
Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'time' => now()->toIso8601String(),
    ]);
})->middleware('throttle:60,1')->name('health');

// CSP violation reports — receives JSON POST from browsers when the
// Content-Security-Policy (currently report-only) blocks something. Writes
// to the `csp` log channel so we can validate the policy before flipping
// it to enforcing. CSRF-exempt (handled in bootstrap/app.php) because the
// browser doesn't carry our token. Tight rate limit caps the worst-case
// burst from a misconfigured page; a real attacker can't gain anything by
// flooding it (the handler only writes log lines, no DB / network IO).
Route::post('/csp-report', [CspReportController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('csp.report');
