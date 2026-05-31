<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\SignupProvisioner;
use App\Services\Billing\SignupProvisionResult;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;

/**
 * billing:reconcile-session — replay account provisioning against ONE existing
 * Stripe Checkout Session ID (the single-session counterpart to the
 * signup:reconcile sweep). Provisions User + Workspace + WorkspaceMember +
 * Cashier subscription, exactly as the success URL would, without re-typing
 * the form.
 *
 * Used when:
 *   - A bug in the success handler aborted before provisioning.
 *   - A network blip dropped the success-URL redirect mid-provision.
 *   - A future incident leaves an orphan Stripe session/sub to reconcile.
 *
 * As of [[signup_hardening]] this DELEGATES to the shared, idempotent
 * App\Services\Billing\SignupProvisioner — the SAME path the success() redirect,
 * the webhook safety net, and signup:reconcile all use. There is no longer a
 * private copy of the provisioning transaction here (it used to be a 3rd
 * duplicate that drifted out of sync and carried a method_exists($array) bug).
 *
 * Idempotent. If the user already exists, short-circuits without re-creating.
 *
 * Usage:
 *   railway ssh --service app -- php artisan billing:reconcile-session \
 *     --session=cs_live_b1FBsQ6qlK... --apply
 *
 * Without --apply this is a dry-run that shows exactly what would happen.
 */
class ReconcileCheckoutSession extends Command
{
    protected $signature = 'billing:reconcile-session
        {--session= : Stripe Checkout Session ID (cs_live_... or cs_test_...)}
        {--apply : Actually provision (default is dry-run)}';

    protected $description = 'Replay account provisioning against an existing Stripe Checkout Session (delegates to SignupProvisioner).';

    public function handle(SignupProvisioner $provisioner): int
    {
        $sessionId = trim((string) $this->option('session'));
        $apply = (bool) $this->option('apply');

        if ($sessionId === '' || ! str_starts_with($sessionId, 'cs_')) {
            $this->error('--session is required and must look like cs_live_... or cs_test_...');
            return self::FAILURE;
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription', 'customer'],
            ]);
        } catch (\Throwable $e) {
            $this->error('Stripe session retrieve failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $subscription = $session->subscription ?? null;
        $paymentStatus = $session->payment_status ?? null;
        $subStatus = $subscription->status ?? null;
        $paid = $paymentStatus === 'paid' || $paymentStatus === 'no_payment_required';
        $trialing = $subStatus === 'trialing';

        $meta = $this->metadataOf($session);
        $email = strtolower($meta['email'] ?? ($session->customer_email ?? ''));
        $name = $meta['name'] ?? null;
        $workspaceName = $meta['workspace_name'] ?? null;
        $plan = $meta['plan'] ?? 'solo';
        $stripeCustomerId = is_string($session->customer ?? null)
            ? $session->customer
            : ($session->customer->id ?? null);

        $this->info('=== Stripe session details ===');
        $this->line('  session id:      ' . $session->id);
        $this->line('  payment_status:  ' . ($paymentStatus ?? '(null)'));
        $this->line('  subscription:    ' . ($subscription->id ?? '(none)'));
        $this->line('  sub status:      ' . ($subStatus ?? '(none)'));
        $this->line('  customer id:     ' . ($stripeCustomerId ?? '(none)'));
        $this->newLine();
        $this->info('=== Metadata to provision ===');
        $this->line('  email:           ' . ($email ?: '(EMPTY)'));
        $this->line('  name:            ' . ($name ?: '(EMPTY)'));
        $this->line('  workspace_name:  ' . ($workspaceName ?: '(EMPTY)'));
        $this->line('  plan:            ' . $plan);
        $this->newLine();

        if (! $paid && ! $trialing) {
            $this->error("Refusing to reconcile: session is neither paid nor trialing. payment_status={$paymentStatus} sub_status={$subStatus}");
            return self::FAILURE;
        }

        if (! $email || ! $name || ! $workspaceName) {
            $this->error('Refusing to reconcile: session metadata is incomplete (email, name, or workspace_name missing).');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->warn("User {$email} already exists in DB. Nothing to reconcile — log in instead.");
            return self::SUCCESS;
        }

        if (! $apply) {
            $priceId = $subscription->items->data[0]->price->id ?? null;
            $this->comment('DRY-RUN. Would provision via SignupProvisioner:');
            $this->line('  User       — name="' . $name . '" email="' . $email . '"');
            $this->line('  Workspace  — name="' . $workspaceName . '" plan="' . $plan . '" stripe_customer_id="' . $stripeCustomerId . '"');
            $this->line('  Member     — role=owner, accepted_at=now');
            if ($subscription) {
                $this->line('  Subscription — stripe_id="' . $subscription->id . '" status="' . $subStatus . '" price="' . $priceId . '"');
            }
            $this->line('  Welcome email — credential email queued on the pinned Resend transport.');
            $this->comment('Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        // Provision via the SHARED idempotent service. sendWelcomeEmail=true:
        // this is an out-of-band reconcile with no browser session, so the
        // credential email IS the customer's way in (the old command generated
        // a temp password but never emailed it — a real gap, now fixed).
        $result = $provisioner->provisionFromSession($session, sendWelcomeEmail: true);

        if ($result->status === SignupProvisionResult::ALREADY_PROVISIONED) {
            $this->warn('Already provisioned (raced) — nothing to do.');
            return self::SUCCESS;
        }

        if (! $result->wasProvisioned()) {
            $this->error("Provisioning did not complete: {$result->status} ({$result->reason})");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Reconciled.');
        $this->line('  user id:       ' . $result->user?->id);
        $this->line('  workspace id:  ' . $result->workspace?->id . ' (slug=' . $result->workspace?->slug . ')');
        $this->newLine();
        $this->info('A welcome email with login credentials was queued on the pinned Resend transport.');
        $this->line('If it does not arrive, the customer can reset via /agency/password-reset/request.');

        return self::SUCCESS;
    }

    /** Normalise Stripe metadata (StripeObject or array) to a plain array. */
    private function metadataOf(object $session): array
    {
        $raw = $session->metadata ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw) && method_exists($raw, 'toArray')) {
            return $raw->toArray();
        }
        return [];
    }
}
