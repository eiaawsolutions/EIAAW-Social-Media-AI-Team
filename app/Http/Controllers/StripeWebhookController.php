<?php

namespace App\Http\Controllers;

use App\Mail\TrialEndingSoon;
use App\Models\SubscriptionEvent;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
                // No-op for the signup flow — BillingController::success has
                // already provisioned the account synchronously by the time
                // the webhook lands. Reserved for future panel-side upgrade
                // flows that set metadata.intent='upgrade' and pass the
                // target plan; idempotency keeps it safe to re-process.
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

        $updates = [
            'subscription_status' => $newStatus,
            'trial_ends_at' => $sub->trial_ends_at,
            'past_due_at' => $newStatus === 'past_due' ? ($workspace->past_due_at ?? now()) : null,
            'canceled_at' => $newStatus === 'canceled' ? ($workspace->canceled_at ?? now()) : null,
        ];

        if ($workspace->isSuspended && in_array($newStatus, ['trialing', 'active'], true)) {
            $updates['suspended_at'] = null;
            $updates['suspended_reason'] = null;
            Log::info("Workspace {$workspace->slug} un-suspended after subscription returned to {$newStatus}.");
        }

        $workspace->update($updates);
        Log::info("Workspace {$workspace->slug} synced from Cashier on {$eventType}: status={$newStatus}, trial_ends_at=" . ($sub->trial_ends_at?->toIso8601String() ?? 'null'));
    }
}
