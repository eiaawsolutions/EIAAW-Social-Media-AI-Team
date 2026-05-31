<?php

namespace App\Services\Billing;

use App\Mail\WelcomeWithCredentials;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\MailTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Single, idempotent account-provisioning path for a completed Stripe Checkout.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this service, account creation lived ONLY inside
 * BillingController::success() — the browser redirect Stripe sends the
 * customer to after payment. If that redirect never fired (tab closed,
 * network drop, a 500 after the charge), the customer was charged in Stripe
 * but had NO account and NO recovery path. See [[signup_provisioning_gap]].
 *
 * The fix is to make provisioning a pure function of the Stripe Checkout
 * Session, callable from TWO places that race harmlessly:
 *
 *   1. BillingController::success()            — the happy path (redirect)
 *   2. StripeWebhookController (checkout.session.completed) — the safety net
 *
 * Both call provisionFromSession(). Whichever wins creates the account; the
 * loser short-circuits on the email-exists guard. The webhook path does NOT
 * auto-login or set the welcome cookie (there's no browser session) — it just
 * guarantees the account + welcome email exist.
 *
 * IDEMPOTENCY
 * -----------
 * Keyed on the user email (the same guard success() already used). A second
 * call for an already-provisioned email returns ::alreadyProvisioned() without
 * touching the DB a second time. Safe under Stripe's at-least-once webhook
 * delivery and under a webhook-then-redirect (or redirect-then-webhook) race.
 */
class SignupProvisioner
{
    /**
     * Provision (or no-op) from an already-retrieved Stripe Checkout Session.
     *
     * @param  \Stripe\Checkout\Session  $session  retrieved with ['expand' => ['subscription', 'customer']]
     * @param  bool  $sendWelcomeEmail  webhook path passes true; success() passes false because it
     *                                  sends the credential-bearing email itself (it owns the temp password
     *                                  needed for auto-login + the welcome cookie).
     * @return SignupProvisionResult
     */
    public function provisionFromSession(object $session, bool $sendWelcomeEmail = true): SignupProvisionResult
    {
        $subscription = $session->subscription ?? null;

        // Stripe metadata is a \Stripe\StripeObject; (array) cast mangles keys.
        // Use ->toArray() so the keys are the real metadata field names. Guard
        // the type before method_exists() — PHP 8.3 throws a TypeError if it's
        // handed an array (which happens for already-decoded payloads / tests).
        $rawMetadata = $session->metadata ?? null;
        $metadata = is_array($rawMetadata)
            ? $rawMetadata
            : (is_object($rawMetadata) && method_exists($rawMetadata, 'toArray')
                ? $rawMetadata->toArray()
                : []);

        // Only signup sessions provision here. Upgrade/other intents are
        // handled elsewhere — this prevents a future panel-side checkout from
        // accidentally minting a duplicate account.
        $intent = $metadata['intent'] ?? null;
        if ($intent !== null && $intent !== 'signup') {
            return SignupProvisionResult::skipped('non-signup intent: '.$intent);
        }

        $email = strtolower($metadata['email'] ?? ($session->customer_email ?? ''));
        $name = $metadata['name'] ?? null;
        $workspaceName = $metadata['workspace_name'] ?? null;
        $plan = $metadata['plan'] ?? 'solo';

        if (! $email || ! $name || ! $workspaceName) {
            Log::error('SignupProvisioner: missing checkout metadata', [
                'session_id' => $session->id ?? null,
                'metadata' => $metadata,
            ]);
            return SignupProvisionResult::failed('missing metadata');
        }

        // Idempotency — whoever provisioned first wins; everyone else no-ops.
        $existing = User::where('email', $email)->first();
        if ($existing) {
            return SignupProvisionResult::alreadyProvisioned($existing);
        }

        $tempPassword = Str::password(12, symbols: false);

        try {
            [$user, $workspace] = DB::transaction(function () use ($email, $name, $workspaceName, $plan, $tempPassword, $session, $subscription) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                ]);

                $slug = Str::slug($workspaceName);
                if (Workspace::where('slug', $slug)->exists()) {
                    $slug = $slug.'-'.Str::lower(Str::random(6));
                }

                $stripeCustomerId = is_string($session->customer)
                    ? $session->customer
                    : ($session->customer->id ?? null);

                $stripeStatus = $subscription->status ?? 'active';
                $workspaceStatus = $stripeStatus === 'trialing' ? 'trialing' : 'active';
                $trialEndsAt = ($subscription && ! empty($subscription->trial_end))
                    ? Carbon::createFromTimestamp($subscription->trial_end)
                    : null;

                $workspace = Workspace::create([
                    'slug' => $slug,
                    'name' => $workspaceName,
                    'owner_id' => $user->id,
                    'type' => $plan === 'solo' ? 'solo' : 'agency',
                    'plan' => $plan,
                    'subscription_status' => $workspaceStatus,
                    'trial_ends_at' => $trialEndsAt,
                    'stripe_customer_id' => $stripeCustomerId,
                ]);

                WorkspaceMember::create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'invited_at' => now(),
                    'accepted_at' => now(),
                ]);

                $user->forceFill(['current_workspace_id' => $workspace->id])->save();

                if ($subscription) {
                    $priceId = $subscription->items->data[0]->price->id ?? null;

                    $workspace->subscriptions()->create([
                        'type' => 'default',
                        'stripe_id' => $subscription->id,
                        'stripe_status' => $subscription->status,
                        'stripe_price' => $priceId,
                        'quantity' => 1,
                        'trial_ends_at' => $trialEndsAt,
                        'ends_at' => null,
                    ]);
                }

                return [$user, $workspace];
            });
        } catch (\Throwable $e) {
            Log::error('SignupProvisioner: provisioning transaction failed', [
                'session_id' => $session->id ?? null,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return SignupProvisionResult::failed($e->getMessage());
        }

        // The webhook (safety-net) path emails credentials itself because it
        // has no browser session to bridge the password through. The success()
        // path passes false and sends its own copy after wiring the cookie.
        if ($sendWelcomeEmail) {
            self::queueWelcome($user, $workspace, $tempPassword);
            Log::info('SignupProvisioner: account provisioned via webhook safety net', [
                'workspace_id' => $workspace->id,
                'email' => $email,
            ]);
        }

        return SignupProvisionResult::provisioned($user, $workspace, $tempPassword);
    }

    /**
     * Queue the credential-bearing welcome email on a PINNED, verified-
     * deliverable transport. Shared by the webhook path here and the success()
     * redirect (which calls this after wiring the welcome cookie) so there is
     * exactly ONE place that knows how to send credentials.
     *
     * If the pinned transport can't deliver, we log LOUDLY (the customer's only
     * copy of their password is at stake) but still attempt the queue so a
     * later transport fix + worker retry can recover.
     */
    public static function queueWelcome(User $user, Workspace $workspace, string $tempPassword): void
    {
        $mailer = MailTransport::welcomeMailer();
        if ($reason = MailTransport::cannotDeliverReason($mailer)) {
            Log::critical('SignupProvisioner: welcome credentials may NOT deliver — transport broken', [
                'user_id' => $user->id,
                'mailer' => $mailer,
                'reason' => $reason,
            ]);
        }

        try {
            Mail::mailer($mailer)->to($user->email)->queue(new WelcomeWithCredentials(
                user: $user,
                workspace: $workspace,
                tempPassword: $tempPassword,
                loginUrl: url('/agency/login'),
            ));
        } catch (\Throwable $e) {
            Log::error('SignupProvisioner: welcome email queue failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
