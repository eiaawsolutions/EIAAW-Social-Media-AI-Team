<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\EnterpriseEnquiryController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SupportChatController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

Route::get('/', fn () => view('landing'))->name('landing');

// Floating support chatbot — public, CSRF-exempt (the landing widget carries no
// session token; panel widgets reuse the same endpoint). Both are tightly rate-
// limited: AI chat costs tokens per call, and the contact form is a lead-write.
// The chat surface enforces its own scope/guardrails in ChatbotPrompts + the
// LlmGateway injection detector. CSRF exemption is declared in bootstrap/app.php.
Route::post('/api/chatbot', [SupportChatController::class, 'chat'])
    ->middleware('throttle:10,1')
    ->name('support.chatbot');

Route::post('/api/contact', [SupportChatController::class, 'contact'])
    ->middleware('throttle:6,1')
    ->name('support.contact');

// Static info / legal pages linked from the site footer. Plain view routes
// (no controller) — content is fully static so there is nothing to compute.
// These MUST exist: a paid SaaS with Stripe billing cannot 404 on Privacy /
// Terms, and the PDPA requires a reachable privacy notice.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/acceptable-use', 'legal.acceptable-use')->name('legal.acceptable-use');
Route::view('/ai-disclaimer', 'legal.ai-disclaimer')->name('legal.ai-disclaimer');
Route::view('/dpa', 'legal.dpa')->name('legal.dpa');
Route::view('/security', 'legal.security')->name('legal.security');
// Changelog hidden from all users (route disabled). View + CHANGELOG.md kept
// on disk; re-enable by uncommenting and restoring the footer link.
// Route::view('/changelog', 'legal.changelog')->name('legal.changelog');
Route::view('/legal', 'legal.index')->name('legal.index');

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

// Enterprise tier — a "Talk to us" lead flow, NOT a Stripe checkout. The
// GET renders the dedicated enquiry form; the POST persists an
// EnterpriseEnquiry + notifies HQ. The POST is a normal CSRF-protected
// browser form (it carries @csrf), so unlike /api/contact it is NOT
// CSRF-exempt. Tight write-throttle to deter scripted lead spam.
Route::get('/enterprise', [EnterpriseEnquiryController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('enterprise.contact');
Route::post('/enterprise', [EnterpriseEnquiryController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('enterprise.contact.store');

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
