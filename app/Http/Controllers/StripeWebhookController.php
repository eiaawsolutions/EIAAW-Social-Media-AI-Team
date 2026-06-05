<?php

namespace App\Http\Controllers;

use App\Mail\TrialEndingSoon;
use App\Models\SubscriptionEvent;
use App\Models\Workspace;
use App\Services\Billing\SignupProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook handler — mirrors the employee-portal pattern adapted to
 * the Workspace billable model. Extends Cashier's controller and adds:
 *
 *   1. Idempotency: every Stripe event ID is recorded in subscription_events
 *      before processing (Stripe retries failed deliveries up to 3 days).
 *
 *   2. Workspace resolution: webhooks arrive without our subdomain context,
 *      so we resolve the workspace from stripe_customer_id in the payload.
 *
 *   3. Failed payment → past_due flag with 3-day grace; success → active.
 *
 *   4. Subscription canceled → 30-day read-only grace via canceled_at.
 *
 * The route is registered in routes/web.php as POST /stripe/webhook with
 * Cashier's signature-verify middleware (requires STRIPE_WEBHOOK_SECRET).
 */
class StripeWebhookController extends CashierWebhookController
{
    public function __construct(private readonly SignupProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        // Strict shape validation. The signature middleware already proved
        // the body came from Stripe — these checks defend against future
        // Stripe API changes / library bugs that could emit a malformed event.
        if (! is_array($payload)) {
            return new Response('Bad payload', 400);
        }
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;
        if (! is_string($eventId) || ! is_string($eventType)
            || ! preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)
            || strlen($eventType) > 120
            || ! isset($payload['data']['object']) || ! is_array($payload['data']['object'])
        ) {
            Log::warning('Stripe webhook: rejected malformed payload', [
                'id_present'   => is_string($eventId),
                'type_present' => is_string($eventType),
            ]);
            return new Response('Bad payload', 400);
        }

        $existing = SubscriptionEvent::where('stripe_event_id', $eventId)->first();
        if ($existing && $existing->processed_at) {
            return new Response('Already processed', 200);
        }

        $workspaceId = $this->resolveWorkspaceFromPayload($payload);

        $event = $existing ?: SubscriptionEvent::create([
            'workspace_id' => $workspaceId,
            'stripe_event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        try {
            // SIGNUP SAFETY NET — must run BEFORE the workspace-found gate.
            // On checkout.session.completed for a signup, the workspace does
            // not exist yet if BillingController::success() never ran (tab
            // closed, network drop, redirect 500 after the charge). This is
            // the ONLY recovery path for a customer who paid but was never
            // provisioned ([[signup_provisioning_gap]]). Idempotent: if
            // success() already provisioned, SignupProvisioner no-ops on the
            // email-exists guard.
            if ($eventType === 'checkout.session.completed' && ! $workspaceId) {
                $this->provisionSignupIfNeeded($payload);
                // Re-resolve — provisioning just created the workspace, so the
                // standard side-effect sync below can now find it.
                $workspaceId = $this->resolveWorkspaceFromPayload($payload);
                if ($workspaceId && ! $event->workspace_id) {
                    $event->update(['workspace_id' => $workspaceId]);
                }
            }

            // Let Cashier do its standard subscription-table updates first.
            parent::handleWebhook($request);

            if ($workspaceId) {
                $workspace = Workspace::find($workspaceId);
                if ($workspace) {
                    $this->applySaaSSideEffects($eventType, $payload, $workspace);
                }
            }

            $event->update(['processed_at' => now()]);

            return new Response('OK', 200);
        } catch (\Throwable $e) {
            Log::error("Stripe webhook {$eventType} ({$eventId}) failed: " . $e->getMessage(), [
                'workspace_id' => $workspaceId,
                'trace' => $e->getTraceAsString(),
            ]);
            $event->update(['processing_error' => substr($e->getMessage(), 0, 1000)]);
            // Return 500 so Stripe retries; idempotency above prevents
            // double-processing once we recover.
            return new Response('Processing error', 500);
        }
    }

    /**
     * Recover a paid-but-unprovisioned signup. Retrieves the full Checkout
     * Session from Stripe (the webhook payload omits expanded subscription +
     * customer) and runs the SAME idempotent provisioner success() uses, so
     * the result is identical whichever path wins. Best-effort: a failure here
     * is logged and bubbles up to force a Stripe retry (the parent handler's
     * try/catch turns a throw into a 500). Only acts on signup sessions; the
     * provisioner itself skips non-signup intents.
     */
    private function provisionSignupIfNeeded(array $payload): void
    {
        $sessionId = $payload['data']['object']['id'] ?? null;
        $intent = $payload['data']['object']['metadata']['intent'] ?? null;
        $paymentStatus = $payload['data']['object']['payment_status'] ?? null;

        // Only signup sessions provision here. Skip upgrades / unknown intents.
        if ($intent !== 'signup') {
            return;
        }

        if (! $sessionId) {
            Log::warning('Stripe webhook: signup checkout.session.completed with no session id — cannot recover.');
            return;
        }

        // 'unpaid' here would mean a $0/async flow; v1 charges on completion so
        // we expect 'paid' (or 'no_payment_required' under a 100%-off coupon).
        if ($paymentStatus !== null && ! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            Log::info("Stripe webhook: signup session {$sessionId} payment_status={$paymentStatus} — not provisioning yet.");
            return;
        }

        $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription', 'customer'],
        ]);

        $result = $this->provisioner->provisionFromSession($session, sendWelcomeEmail: true);

        if ($result->wasProvisioned()) {
            Log::warning("Stripe webhook: RECOVERED stranded signup — provisioned account for session {$sessionId} that success() never finished.", [
                'workspace_id' => $result->workspace?->id,
            ]);
        }
    }

    private function resolveWorkspaceFromPayload(array $payload): ?int
    {
        $customerId = $payload['data']['object']['customer']
            ?? $payload['data']['object']['id']  // for customer.* events the object IS the customer
            ?? null;

        if (! $customerId) {
            return null;
        }

        return Workspace::where('stripe_customer_id', $customerId)->value('id');
    }

    private function applySaaSSideEffects(string $eventType, array $payload, Workspace $workspace): void
    {
        switch ($eventType) {
            // Cashier's parent handler updated the subscriptions row before we got here.
            // Mirror its state onto the workspace's denormalised columns. Source of truth
            // is the Cashier row, which Cashier wrote from the actual Stripe payload.
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'invoice.payment_succeeded':
                // ENTERPRISE: a one-off invoice (metadata.intent=enterprise) has NO
                // subscription, so it must NOT go through syncWorkspaceFromCashier
                // (which expects a Cashier subscription row and would warn+skip).
                // Activate the bespoke workspace directly and stop. Idempotent.
                if ($eventType === 'invoice.payment_succeeded'
                    && $this->activateEnterpriseIfInvoicePaid($payload, $workspace)
                ) {
                    break;
                }
                $this->syncWorkspaceFromCashier($workspace, $eventType);
                break;

            case 'invoice.payment_failed':
                $workspace->update([
                    'subscription_status' => 'past_due',
                    'past_due_at' => $workspace->past_due_at ?? now(),
                ]);
                Log::warning("Workspace {$workspace->slug} payment failed — past_due flag set.");
                break;

            case 'customer.subscription.deleted':
                $workspace->update([
                    'subscription_status' => 'canceled',
                    'canceled_at' => $workspace->canceled_at ?? now(),
                ]);
                Log::info("Workspace {$workspace->slug} subscription canceled — 30-day read-only grace begins.");
                break;

            case 'customer.subscription.trial_will_end':
                // Stripe fires this T-3 days before trial_end. Queue a
                // friendly reminder. Idempotent because subscription_events
                // dedupes on stripe_event_id.
                if ($workspace->owner) {
                    try {
                        Mail::to($workspace->owner->email)->queue(new TrialEndingSoon($workspace));
                        Log::info("Workspace {$workspace->slug} — trial-ending reminder queued.");
                    } catch (\Throwable $e) {
                        Log::error("Workspace {$workspace->slug} — trial reminder queue failed: ".$e->getMessage());
                    }
                }
                break;

            case 'checkout.session.completed':
                // Upgrade flow (existing workspace changing plan).
                $session = $payload['data']['object'] ?? [];
                $intent = $session['metadata']['intent'] ?? null;
                $newPlan = $session['metadata']['plan'] ?? null;
                if ($intent === 'upgrade' && $newPlan && $workspace->plan !== $newPlan) {
                    $workspace->update(['plan' => $newPlan]);
                    Log::info("Workspace {$workspace->slug} upgraded to plan={$newPlan} via checkout.session.completed.");
                }
                break;
        }
    }

    /**
     * Mirror the Cashier subscriptions row onto the workspace columns.
     *
     * The Cashier row is updated by the parent webhook handler from the actual
     * Stripe event payload. The workspace columns are a denormalised cache
     * for fast hasActiveAccess() lookups in middleware. Keeping them in sync
     * is THE invariant the middleware depends on.
     *
     * Crucial detail: do NOT clear trial_ends_at on invoice.payment_succeeded
     * if the underlying Stripe subscription is still trialing. Stripe sends
     * a $0 invoice.payment_succeeded at the START of a trial when the
     * subscription was created with a coupon that zeroes the first invoice
     * (e.g., the founder coupon). The trial isn't over — the customer is
     * just starting. Only flip to active+null trial_ends_at when the
     * underlying subscription has actually transitioned to 'active'.
     */
    private function syncWorkspaceFromCashier(Workspace $workspace, string $eventType): void
    {
        $sub = $workspace->subscriptions()
            ->where('type', 'default')
            ->orderByDesc('id')
            ->first();

        if (! $sub) {
            Log::warning("syncWorkspaceFromCashier: no Cashier subscription for workspace {$workspace->slug} on {$eventType} — skipping.");
            return;
        }

        $newStatus = match ($sub->stripe_status) {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due' => 'past_due',
            'canceled', 'unpaid', 'incomplete_expired' => 'canceled',
            default => $workspace->subscription_status, // unknown statuses → leave as-is
        };

        // A subscription scheduled to cancel at period end keeps stripe_status
        // 'active' (Cashier stores ends_at separately). We intentionally do NOT
        // flip subscription_status to 'canceled' here — the customer keeps full
        // access during the grace window, and onCancellationGracePeriod() reads
        // ends_at directly. Only the terminal customer.subscription.deleted
        // event (handled in applySaaSSideEffects) sets 'canceled' + canceled_at.
        $scheduledToCancel = $sub->ends_at !== null && $sub->ends_at->isFuture();

        $updates = [
            'subscription_status' => $newStatus,
            'trial_ends_at' => $sub->trial_ends_at,
            'past_due_at' => $newStatus === 'past_due' ? ($workspace->past_due_at ?? now()) : null,
            // PRESERVE canceled_at by default. The terminal customer.subscription.deleted
            // event (applySaaSSideEffects) is the SOLE writer of canceled_at — it
            // stamps the moment cancellation took effect, which anchors the 30-day
            // read-only-grace window (Workspace::readOnlyGraceEndsAt). syncWorkspaceFromCashier
            // must never overwrite or null it on an intermediate transition (e.g. a
            // past_due blip during grace) — doing so would either lose the timestamp
            // or, on a later deletion, reset the grace clock to the deletion time.
            // The ONLY case where we clear it is a genuine resume (handled below).
            'canceled_at' => $workspace->canceled_at,
        ];

        // Resume: a previously cancel-at-period-end subscription is active again
        // with ends_at cleared. Null any stale canceled_at so the read-only-grace
        // window doesn't fire for a customer who reactivated.
        if (in_array($newStatus, ['trialing', 'active'], true) && ! $scheduledToCancel) {
            $updates['canceled_at'] = null;
        }

        if ($workspace->isSuspended && in_array($newStatus, ['trialing', 'active'], true)) {
            $updates['suspended_at'] = null;
            $updates['suspended_reason'] = null;
            Log::info("Workspace {$workspace->slug} un-suspended after subscription returned to {$newStatus}.");
        }

        $workspace->update($updates);
        Log::info("Workspace {$workspace->slug} synced from Cashier on {$eventType}: status={$newStatus}, trial_ends_at=" . ($sub->trial_ends_at?->toIso8601String() ?? 'null'));
    }

    /**
     * Activate a bespoke ENTERPRISE workspace when its one-off invoice is paid.
     *
     * Enterprise has no subscription — it's a single Stripe Invoice carrying
     * metadata.intent=enterprise (set by EnterpriseProvisioner). When that invoice
     * is paid, Stripe fires invoice.payment_succeeded with the same metadata; we
     * flip the workspace to 'active' and stamp the matching enterprise_enquiries
     * row (invoice_status=paid). Returns true when this WAS an enterprise invoice
     * (so the caller skips the subscription-sync path), false otherwise.
     *
     * Idempotent: re-running on an already-active workspace is a harmless no-op
     * (Stripe may redeliver; subscription_events also dedupes upstream).
     */
    private function activateEnterpriseIfInvoicePaid(array $payload, Workspace $workspace): bool
    {
        $invoice = $payload['data']['object'] ?? [];
        $intent = $invoice['metadata']['intent'] ?? null;

        if ($intent !== 'enterprise') {
            return false; // not an enterprise invoice — let the normal path run
        }

        // Only act when the invoice is genuinely paid (defensive: the event name
        // already implies it, but the object carries the authoritative flag).
        $isPaid = ($invoice['paid'] ?? false) === true
            || ($invoice['status'] ?? null) === 'paid';

        if (! $isPaid) {
            Log::info("Stripe webhook: enterprise invoice for workspace {$workspace->slug} not marked paid yet — skipping activation.");
            return true; // it IS enterprise; just nothing to activate yet
        }

        if ($workspace->subscription_status !== 'active') {
            $workspace->update([
                'subscription_status' => 'active',
                'suspended_at' => null,
                'suspended_reason' => null,
            ]);
            Log::info("Workspace {$workspace->slug} ACTIVATED via paid enterprise invoice.");
        }

        // Stamp the deal record (best-effort; the workspace activation above is
        // the load-bearing effect).
        $invoiceId = $invoice['id'] ?? null;
        if ($invoiceId) {
            \App\Models\EnterpriseEnquiry::where('stripe_invoice_id', $invoiceId)
                ->whereNull('invoice_paid_at')
                ->update(['invoice_status' => 'paid', 'invoice_paid_at' => now(), 'status' => 'qualified']);
        }

        return true;
    }
}
