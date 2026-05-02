<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

/**
 * billing:reconcile-session — replay BillingController::success against an
 * existing Stripe Checkout Session ID. Provisions User + Workspace +
 * WorkspaceMember + Cashier subscription row, exactly as the success URL
 * would, but without re-typing the form.
 *
 * Used when:
 *   - A bug in the success handler aborted before provisioning (the
 *     "Checkout metadata incomplete" cast bug, fixed in adf4eaf).
 *   - A network blip dropped the success-URL redirect mid-provision.
 *   - A future incident leaves orphan Stripe sessions/subs that need to
 *     be reconciled into the DB.
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

    protected $description = 'Replay BillingController::success provisioning against an existing Stripe Checkout Session.';

    public function handle(): int
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
        $paid = $paymentStatus === 'paid';
        $trialing = $subStatus === 'trialing';

        $rawMetadata = $session->metadata ?? null;
        $metadata = $rawMetadata && method_exists($rawMetadata, 'toArray')
            ? $rawMetadata->toArray()
            : (is_array($rawMetadata) ? $rawMetadata : []);

        $email = strtolower($metadata['email'] ?? ($session->customer_email ?? ''));
        $name = $metadata['name'] ?? null;
        $workspaceName = $metadata['workspace_name'] ?? null;
        $plan = $metadata['plan'] ?? 'solo';

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
            $this->comment('DRY-RUN. Would create:');
            $this->line('  User       — name="' . $name . '" email="' . $email . '"');
            $this->line('  Workspace  — name="' . $workspaceName . '" plan="' . $plan . '" stripe_customer_id="' . $stripeCustomerId . '"');
            $this->line('  Member     — role=owner, accepted_at=now');
            if ($subscription) {
                $priceId = $subscription->items->data[0]->price->id ?? null;
                $this->line('  Subscription — stripe_id="' . $subscription->id . '" status="' . $subStatus . '" price="' . $priceId . '"');
            }
            $this->comment('Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        $tempPassword = Str::password(12, symbols: false);

        try {
            [$user, $workspace] = DB::transaction(function () use ($email, $name, $workspaceName, $plan, $tempPassword, $session, $subscription, $stripeCustomerId) {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                ]);

                $slug = Str::slug($workspaceName);
                if (Workspace::where('slug', $slug)->exists()) {
                    $slug = $slug . '-' . Str::lower(Str::random(6));
                }

                $trialEndsAt = ($subscription && ! empty($subscription->trial_end))
                    ? Carbon::createFromTimestamp($subscription->trial_end)
                    : now()->addDays(config("billing.plans.{$plan}.trial_days", 14));

                $workspace = Workspace::create([
                    'slug' => $slug,
                    'name' => $workspaceName,
                    'owner_id' => $user->id,
                    'type' => $plan === 'solo' ? 'solo' : 'agency',
                    'plan' => $plan,
                    'subscription_status' => 'trialing',
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
            Log::error('billing:reconcile-session provisioning failed', [
                'session_id' => $sessionId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            $this->error('Provisioning failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Reconciled.');
        $this->line('  user id:       ' . $user->id);
        $this->line('  workspace id:  ' . $workspace->id . ' (slug=' . $workspace->slug . ')');
        $this->newLine();
        $this->warn('IMPORTANT: temp password was generated but NOT emailed (this is a one-shot reconcile, not the public flow).');
        $this->warn('Use "Forgot password" at /agency/login to set a new password — or run:');
        $this->line('  php artisan tinker --execute=' . "'\\App\\Models\\User::find({$user->id})->sendPasswordResetNotification(...)'");
        $this->warn('OR just have the user reset via /agency/forgot-password.');

        return self::SUCCESS;
    }
}
