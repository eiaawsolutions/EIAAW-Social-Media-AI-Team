<?php

use App\Http\Controllers\SignupController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::get('/', fn () => view('landing'))->name('landing');

Route::redirect('/login', '/agency/login')->name('login');

// Public signup funnel — see SignupController for the full why.
// Landing CTAs link here; the chosen plan is stashed in session and consumed
// by App\Filament\Agency\Auth\Register inside the registration transaction.
Route::get('/signup', [SignupController::class, 'picker'])->name('signup.picker');
Route::get('/signup/{plan}', [SignupController::class, 'selectPlan'])->name('signup.select');
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
