<?php

namespace App\Http\Controllers;

use App\Mail\WelcomeWithCredentials;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\StripePriceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
    public function __construct(private readonly StripePriceCache $priceCache) {}

    /**
     * Step 3: user submitted name+email+workspace from /signup/{plan}.
     * Lazy-create the Stripe Price, create a Stripe Checkout Session with a
     * 14-day trial, redirect to Stripe.
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

        $session = $stripe->checkout->sessions->create(array_merge($customerArg, [
            'mode'                 => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'           => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'subscription_data' => [
                'trial_period_days' => $planConfig['trial_days'],
                'metadata'          => [
                    'plan'           => $plan,
                    'workspace_name' => $validated['workspace_name'],
                ],
            ],
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
        ]));

        return redirect()->away($session->url);
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

        $metadata = (array) ($session->metadata ?? []);
        $email = strtolower($metadata['email'] ?? ($session->customer_email ?? ''));
        $name  = $metadata['name'] ?? null;
        $workspaceName = $metadata['workspace_name'] ?? null;
        $plan = $metadata['plan'] ?? 'solo';

        if (! $email || ! $name || ! $workspaceName) {
            Log::error('billing.success: missing checkout metadata', [
                'session_id' => $sessionId,
                'metadata'   => $metadata,
            ]);
            return redirect()->route('signup.picker')
                ->with('error', 'Checkout metadata incomplete. Please contact support.');
        }

        // Idempotency — if this email already has a user (e.g. retry after
        // partial provisioning), short-circuit to login.
        if (User::where('email', $email)->exists()) {
            return redirect()->route('agency.login.alias', ['signup' => 'exists']);
        }

        $tempPassword = Str::password(12, symbols: false);

        try {
            [$user, $workspace] = DB::transaction(function () use ($email, $name, $workspaceName, $plan, $tempPassword, $session, $subscription) {
                $user = User::create([
                    'name'     => $name,
                    'email'    => $email,
                    'password' => Hash::make($tempPassword),
                ]);

                $slug = Str::slug($workspaceName);
                if (Workspace::where('slug', $slug)->exists()) {
                    $slug = $slug.'-'.Str::lower(Str::random(6));
                }

                $stripeCustomerId = is_string($session->customer)
                    ? $session->customer
                    : ($session->customer->id ?? null);

                $trialEndsAt = ($subscription && ! empty($subscription->trial_end))
                    ? Carbon::createFromTimestamp($subscription->trial_end)
                    : now()->addDays(config("billing.plans.{$plan}.trial_days", 14));

                $workspace = Workspace::create([
                    'slug'                 => $slug,
                    'name'                 => $workspaceName,
                    'owner_id'             => $user->id,
                    'type'                 => $plan === 'solo' ? 'solo' : 'agency',
                    'plan'                 => $plan,
                    'subscription_status'  => 'trialing',
                    'trial_ends_at'        => $trialEndsAt,
                    'stripe_customer_id'   => $stripeCustomerId,
                ]);

                WorkspaceMember::create([
                    'workspace_id' => $workspace->id,
                    'user_id'      => $user->id,
                    'role'         => 'owner',
                    'invited_at'   => now(),
                    'accepted_at'  => now(),
                ]);

                $user->forceFill(['current_workspace_id' => $workspace->id])->save();

                // Mirror the Stripe subscription into Cashier's subscriptions
                // table so $workspace->subscribed('default') returns true
                // immediately. Cashier's webhook handler will dedupe later
                // arrivals via the unique constraint on stripe_id.
                if ($subscription) {
                    $priceId = $subscription->items->data[0]->price->id ?? null;

                    $workspace->subscriptions()->create([
                        'type'           => 'default',
                        'stripe_id'      => $subscription->id,
                        'stripe_status'  => $subscription->status,
                        'stripe_price'   => $priceId,
                        'quantity'       => 1,
                        'trial_ends_at'  => $trialEndsAt,
                        'ends_at'        => null,
                    ]);
                }

                return [$user, $workspace];
            });
        } catch (\Throwable $e) {
            Log::error('billing.success: provisioning transaction failed', [
                'session_id' => $sessionId,
                'email'      => $email,
                'error'      => $e->getMessage(),
            ]);
            return redirect()->route('signup.picker')
                ->with('error', 'We could not finish setting up your account. Please contact support — your card was charged for a trial.');
        }

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

        // Welcome email — non-fatal, queued.
        try {
            Mail::to($user->email)->queue(new WelcomeWithCredentials(
                user: $user,
                workspace: $workspace,
                tempPassword: $tempPassword,
                loginUrl: url('/agency/login'),
            ));
        } catch (\Throwable $e) {
            Log::error('billing.success: welcome email queue failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

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

        if (! $encrypted) {
            return response()->json(['error' => 'no_welcome_token'], 404);
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return response()->json(['error' => 'invalid_welcome_token'], 404);
        }

        $tempPassword = Cache::pull('eiaaw_welcome_temp_pwd:'.$token);
        if (! $tempPassword) {
            return response()->json(['error' => 'expired_welcome_token'], 404);
        }

        return response()->json([
            'tempPassword' => $tempPassword,
            'email'        => $request->user()?->email,
        ]);
    }
}
