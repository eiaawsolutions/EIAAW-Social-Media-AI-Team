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
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;

        if (! $eventId || ! $eventType) {
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
            case 'invoice.payment_failed':
                $workspace->update([
                    'subscription_status' => 'past_due',
                    'past_due_at' => $workspace->past_due_at ?? now(),
                ]);
                Log::warning("Workspace {$workspace->slug} payment failed — past_due flag set.");
                break;

            case 'invoice.payment_succeeded':
                $updates = [
                    'subscription_status' => 'active',
                    'past_due_at' => null,
                    'trial_ends_at' => null, // first paid invoice ends the trial
                ];
                if ($workspace->isSuspended) {
                    $updates['suspended_at'] = null;
                    $updates['suspended_reason'] = null;
                    Log::info("Workspace {$workspace->slug} un-suspended after payment.");
                }
                $workspace->update($updates);
                break;

            case 'customer.subscription.deleted':
                $workspace->update([
                    'subscription_status' => 'canceled',
                    'canceled_at' => $workspace->canceled_at ?? now(),
                ]);
                Log::info("Workspace {$workspace->slug} subscription canceled — 30-day read-only grace begins.");
                break;

            case 'customer.subscription.updated':
                // Race-safety net: when Stripe transitions a trialing sub to
                // active without a corresponding invoice.payment_succeeded
                // (e.g., zero-amount add-on edits, manual transitions), still
                // settle the workspace flags.
                $sub = $payload['data']['object'] ?? [];
                $stripeStatus = $sub['status'] ?? null;
                $trialEnd = $sub['trial_end'] ?? null;
                if ($stripeStatus === 'active' && empty($trialEnd) && $workspace->subscription_status !== 'active') {
                    $workspace->update([
                        'subscription_status' => 'active',
                        'past_due_at' => null,
                        'trial_ends_at' => null,
                    ]);
                    Log::info("Workspace {$workspace->slug} subscription went active via subscription.updated.");
                }
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
}
