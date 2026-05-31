<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing\SignupProvisioner;
use App\Services\Billing\SignupProvisionResult;
use App\Services\StripePriceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

/**
 * Public signup + Stripe Checkout funnel. Mirrors the Sales-marketing-agent
 * pattern: account is created ONLY after Stripe Checkout succeeds (success
 * URL handler), not on form submit. The 14-day trial lives on the Stripe
 * subscription itself (subscription_data.trial_period_days), not in our DB.
 *
 * Reference implementation:
 *   c:/laragon/www/Sales marketing agent/src/routes/billing.js
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly StripePriceCache $priceCache,
        private readonly SignupProvisioner $provisioner,
    ) {}

    /**
     * Step 3: user submitted name+email+workspace from /signup/{plan}.
     * Lazy-create the Stripe Price, create a Stripe Checkout Session that
     * charges immediately (no trial — see config/billing.php), redirect to Stripe.
     */
    public function checkout(Request $request, string $plan): RedirectResponse
    {
        $plans = config('billing.plans', []);
        if (! isset($plans[$plan])) {
            return redirect()->route('signup.picker')
                ->with('error', 'That plan does not exist. Please choose one below.');
        }

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:120'],
            'email'          => ['required', 'string', 'email:rfc', 'max:190'],
            'workspace_name' => ['required', 'string', 'max:120'],
        ]);

        $email = strtolower($validated['email']);

        // Reject duplicate emails BEFORE hitting Stripe so we don't create
        // orphan Stripe customers for someone who already has an account.
        if (User::where('email', $email)->exists()) {
            return redirect()->route('agency.login.alias', ['email_taken' => 1]);
        }

        $priceId = $this->priceCache->getOrCreate($plan, 'month');

        $stripe = Cashier::stripe();

        // Reuse an existing Stripe customer if this email was used by a
        // previous (canceled) checkout — prevents Stripe from accumulating
        // duplicate customer rows for the same human.
        $existing = $stripe->customers->all(['email' => $email, 'limit' => 1]);
        $customerArg = ! empty($existing->data)
            ? ['customer' => $existing->data[0]->id]
            : ['customer_email' => $email];

        $planConfig = $plans[$plan];

        // Geography gate — v1 is Malaysia-only (config/billing.php
        // 'allowed_countries'). We require billing address collection so
        // Stripe captures the country. Post-checkout (success handler) we
        // verify the address country is in the allowed list and reject
        // otherwise. Stripe's Checkout API doesn't natively restrict the
        // country dropdown for subscription mode, so this is enforced
        // server-side after Stripe returns the session.
        // Once SST is enabled (config/billing.php 'tax.enabled') we also
        // flip automatic_tax + tax_id_collection on so MY customers are
        // charged 8% SST and B2B customers can supply a tax ID.
        $taxEnabled = (bool) config('billing.tax.enabled', false);

        // v1: no free trial. Each workspace requires a paid Blotato account
        // (~$29-$97/mo) that HQ provisions manually — eating that cost on
        // non-converters would be a margin killer. Customers are charged
        // on Checkout completion. We still send `trial_period_days` when
        // it's > 0 in config so the path is easy to flip back later.
        $subscriptionData = [
            'metadata' => [
                'plan'           => $plan,
                'workspace_name' => $validated['workspace_name'],
            ],
        ];
        $trialDays = (int) ($planConfig['trial_days'] ?? 0);
        if ($trialDays > 0) {
            $subscriptionData['trial_period_days'] = $trialDays;
        }

        $sessionArgs = array_merge($customerArg, [
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'billing_address_collection' => 'required',
            'subscription_data' => $subscriptionData,
            'metadata' => [
                'plan'           => $plan,
                'name'           => $validated['name'],
                'email'          => $email,
                'workspace_name' => $validated['workspace_name'],
                'intent'         => 'signup',
            ],
            'success_url'        => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'         => route('signup.picker', ['canceled' => 1]),
            'allow_promotion_codes' => true,
        ]);

        // Only add tax-related blocks when SST is active — sending them
        // with enabled=false confuses Stripe's automatic_tax engine and
        // sometimes returns a 400. Opt-in only.
        if ($taxEnabled) {
            $sessionArgs['automatic_tax'] = ['enabled' => true];
            $sessionArgs['tax_id_collection'] = ['enabled' => true];
            // customer_update is REQUIRED by automatic_tax when reusing an
            // existing customer, so Stripe can refresh the address used
            // for tax calc.
            if (! empty($existing->data)) {
                $sessionArgs['customer_update'] = ['address' => 'auto', 'name' => 'auto'];
            }
        }

        $session = $stripe->checkout->sessions->create($sessionArgs);

        // Defensive allow-list: Stripe controls this URL today, but a
        // compromised SDK or a future API change shouldn't be able to push
        // our customers to an attacker-controlled page. Reject anything that
        // isn't a Stripe-owned host before redirecting.
        $checkoutUrl = $session->url ?? '';
        $host = parse_url($checkoutUrl, PHP_URL_HOST);
        $allowedHosts = ['checkout.stripe.com', 'billing.stripe.com'];
        if (! $host || ! in_array($host, $allowedHosts, true)) {
            Log::error('billing.checkout: Stripe returned non-Stripe URL', [
                'url'  => $checkoutUrl,
                'host' => $host,
            ]);
            return redirect()->route('signup.picker')
                ->with('error', 'Could not start checkout. Please try again or contact support.');
        }

        return redirect()->away($checkoutUrl);
    }

    /**
     * Stripe success URL. Provisions the User + Workspace + WorkspaceMember +
     * Cashier subscription row inside a single transaction. Auto-logs in via
     * the Laravel session, queues a welcome email, and bridges the temp
     * password to the Filament dashboard via a 5-minute httpOnly cookie.
     */
    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        if (! $sessionId) {
            return redirect()->route('signup.picker')
                ->with('error', 'Missing checkout session. Please start again.');
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription', 'customer'],
            ]);
        } catch (\Throwable $e) {
            Log::error('billing.success: Stripe session retrieve failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->route('signup.picker')
                ->with('error', 'We could not verify the checkout session. Please contact support if you were charged.');
        }

        $subscription = $session->subscription ?? null;
        $paid = ($session->payment_status ?? null) === 'paid';
        $trialing = $subscription && ($subscription->status ?? null) === 'trialing';
        if (! $paid && ! $trialing) {
            return redirect()->route('signup.picker', ['payment_failed' => 1]);
        }

        // Geography gate (v1 = Malaysia only). Stripe captured the billing
        // address during checkout; if the customer is outside the allowed
        // list, cancel the subscription AND refund the just-charged invoice
        // (no trial means the customer was charged before we got here).
        // Cancelling alone wouldn't refund the first invoice.
        $allowedCountries = (array) config('billing.allowed_countries', ['MY']);
        $customerCountry = $session->customer_details?->address?->country ?? null;
        if ($customerCountry && ! in_array($customerCountry, $allowedCountries, true)) {
            Log::warning('billing.success: non-allowed country, cancelling + refunding', [
                'session_id' => $sessionId,
                'country' => $customerCountry,
                'allowed' => $allowedCountries,
            ]);

            try {
                if ($subscription?->id) {
                    Cashier::stripe()->subscriptions->cancel($subscription->id);
                }
                // If the subscription is `active` (not `trialing`), the
                // customer was charged. Refund the latest invoice's payment
                // intent. Best-effort; if it fails, we still refuse
                // provisioning and surface a support contact line so the
                // operator can issue the refund manually.
                $isTrialing = ($subscription?->status ?? null) === 'trialing';
                if (! $isTrialing && $subscription?->latest_invoice) {
                    $invoiceId = is_string($subscription->latest_invoice)
                        ? $subscription->latest_invoice
                        : ($subscription->latest_invoice->id ?? null);
                    if ($invoiceId) {
                        $invoice = Cashier::stripe()->invoices->retrieve($invoiceId);
                        $paymentIntentId = $invoice->payment_intent ?? null;
                        if ($paymentIntentId) {
                            Cashier::stripe()->refunds->create([
                                'payment_intent' => is_string($paymentIntentId)
                                    ? $paymentIntentId
                                    : $paymentIntentId->id,
                                'reason' => 'requested_by_customer',
                            ]);
                            Log::info('billing.success: refund issued for non-allowed country', [
                                'invoice_id' => $invoiceId,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('billing.success: cancel/refund failed for non-allowed country', [
                    'error' => $e->getMessage(),
                ]);
            }
            return redirect()->route('signup.picker')
                ->with('error', sprintf(
                    'EIAAW Social Media AI Team is currently available in Malaysia only. Your subscription has been cancelled and any charge will be refunded within 5-10 business days. Email %s if you\'d like to be notified when we launch in %s.',
                    'eiaawsolutions@gmail.com',
                    $customerCountry,
                ));
        }

        // Provision via the shared, idempotent SignupProvisioner — the SAME
        // path the Stripe webhook safety net uses ([[signup_provisioning_gap]]).
        // We pass sendWelcomeEmail=false: the redirect path owns the temp
        // password (needed for auto-login + the welcome cookie) and sends its
        // own credential email below. The webhook path passes true.
        $result = $this->provisioner->provisionFromSession($session, sendWelcomeEmail: false);

        // Idempotency — if the account already existed (webhook won the race,
        // or this is a retried redirect), short-circuit to login.
        if ($result->status === SignupProvisionResult::ALREADY_PROVISIONED) {
            return redirect()->route('agency.login.alias', ['signup' => 'exists']);
        }

        if ($result->status === SignupProvisionResult::FAILED) {
            // Distinguish bad metadata (unrecoverable) from a transient
            // transaction failure, but both surface a support contact line.
            return redirect()->route('signup.picker')
                ->with('error', 'We could not finish setting up your account. Please contact support — your card was charged.');
        }

        if (! $result->wasProvisioned() || ! $result->user || ! $result->workspace) {
            // SKIPPED (non-signup intent) should never reach a signup success
            // URL; treat defensively.
            return redirect()->route('signup.picker')
                ->with('error', 'Checkout could not be completed as a signup. Please contact support.');
        }

        $user = $result->user;
        $workspace = $result->workspace;
        $tempPassword = $result->tempPassword;

        // Auto-login via Laravel session.
        Auth::guard('web')->login($user, true);

        // Bridge the plaintext temp password to the dashboard via a
        // 5-minute httpOnly cookie carrying an opaque token. The actual
        // password lives only in the cache, deleted on first read.
        $bridgeToken = Str::random(40);
        Cache::put('eiaaw_welcome_temp_pwd:'.$bridgeToken, $tempPassword, now()->addMinutes(5));
        Cookie::queue(
            'eiaaw_welcome',
            Crypt::encryptString($bridgeToken),
            5,        // minutes
            '/',
            null,     // domain — null lets Laravel choose
            true,     // secure
            true,     // httpOnly
            false,    // raw
            'lax'     // samesite
        );

        // Welcome email — non-fatal, queued on the PINNED deliverable transport
        // (shared with the webhook safety-net path so credentials are sent
        // exactly one way). $tempPassword is null only for the already-existed
        // branch, which returned above, so it's a string here.
        SignupProvisioner::queueWelcome($user, $workspace, (string) $tempPassword);

        return redirect('/agency?welcome=1');
    }

    /**
     * One-time exchange of the welcome cookie for the temp password. Called
     * by the WelcomeBannerWidget on the dashboard. Cookie + cache key are
     * both burned on first call so the password is single-use.
     */
    public function welcomeToken(Request $request): JsonResponse
    {
        $encrypted = $request->cookie('eiaaw_welcome');

        // Always clear the cookie on the way out — replay protection.
        Cookie::queue(Cookie::forget('eiaaw_welcome'));

        // All failure paths return the SAME generic 404 body so a probe
        // can't tell "you have no welcome cookie" from "your cookie was
        // tampered" from "your password expired." Avoids a user-enumeration
        // / signal channel through differing error strings.
        $genericFailure = fn () => response()->json(['error' => 'unavailable'], 404);

        if (! $encrypted) {
            return $genericFailure();
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return $genericFailure();
        }

        $tempPassword = Cache::pull('eiaaw_welcome_temp_pwd:'.$token);
        if (! $tempPassword) {
            return $genericFailure();
        }

        return response()->json([
            'tempPassword' => $tempPassword,
            'email'        => $request->user()?->email,
        ]);
    }
}
