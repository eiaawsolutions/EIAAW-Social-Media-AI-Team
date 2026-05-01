<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AssignFounderSubscription — give the founder/owner account a real Stripe
 * subscription on the highest plan, with a 100%-off-forever coupon.
 *
 * Why use a real Stripe subscription instead of the eiaaw_internal bypass:
 *   - Every code path that gates on subscribed('default') / hasActiveAccess()
 *     fires identically to a real paying customer.
 *   - The Billing page shows "Active subscription" + the Stripe portal,
 *     not the trial banner.
 *   - Future webhook regressions that would lock a real customer out also
 *     lock us out — caught early.
 *
 * Idempotent. Safe to re-run:
 *   - If the user already has an active subscription on this plan, it's a no-op.
 *   - If the user has a stuck/incomplete subscription, --force will cancel
 *     it and create a new one.
 *
 * Prereqs (MUST be true before running):
 *   1. STRIPE_SECRET is set (live or test).
 *   2. STRIPE_PRICE_<PLAN>_MYR_MONTHLY (or _ANNUAL) is set with the live price ID.
 *      Run `php artisan stripe:sync-prices --apply ...` first if not.
 *   3. The coupon ID exists in Stripe (manually created in dashboard:
 *      Products -> Coupons -> 100% off, Forever, save the ID).
 *
 * Usage:
 *   php artisan founder:assign-subscription \
 *     --email=eiaawsolutions@gmail.com \
 *     --plan=agency \
 *     --period=monthly \
 *     --coupon=FOUNDER_FOREVER \
 *     --apply
 */
class AssignFounderSubscription extends Command
{
    protected $signature = 'founder:assign-subscription
        {--email= : The owner email (required)}
        {--plan=agency : Plan tier — solo|studio|agency (defaults to agency)}
        {--period=monthly : Billing period — monthly|annual}
        {--coupon= : Stripe coupon ID with 100% off forever (required)}
        {--apply : Actually create the Stripe subscription (default is dry-run)}
        {--force : If a subscription already exists, cancel it first and recreate}';

    protected $description = 'Assign a real Stripe subscription with a 100%-off coupon to the founder/owner workspace.';

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $plan = strtolower((string) $this->option('plan'));
        $period = strtolower((string) $this->option('period'));
        $coupon = (string) $this->option('coupon');
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        if ($email === '' || $coupon === '') {
            $this->error('Both --email and --coupon are required.');
            return self::FAILURE;
        }
        if (! in_array($plan, ['solo', 'studio', 'agency'], true)) {
            $this->error("Invalid --plan '{$plan}'. Must be solo, studio, or agency.");
            return self::FAILURE;
        }
        if (! in_array($period, ['monthly', 'annual'], true)) {
            $this->error("Invalid --period '{$period}'. Must be monthly or annual.");
            return self::FAILURE;
        }

        $stripeSecret = env('STRIPE_SECRET');
        if (empty($stripeSecret)) {
            $this->error('STRIPE_SECRET is not set in env.');
            return self::FAILURE;
        }

        $priceEnvKey = strtoupper("STRIPE_PRICE_{$plan}_MYR_{$period}");
        $priceId = env($priceEnvKey);
        if (empty($priceId) || ! str_starts_with($priceId, 'price_')) {
            $this->error("Price ID for {$priceEnvKey} is not set or invalid. Run stripe:sync-prices --apply first.");
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user with email {$email}. Sign up first via /signup, then re-run this command.");
            return self::FAILURE;
        }

        // Pick or create the workspace this user owns.
        $workspace = Workspace::where('owner_id', $user->id)
            ->where('plan', '!=', 'eiaaw_internal')
            ->orderBy('id')
            ->first();

        if (! $workspace) {
            $this->warn('No non-internal workspace owned by this user. Creating one.');
            if (! $apply) {
                $this->comment('(dry-run; would create workspace)');
            } else {
                $workspace = $this->createWorkspaceFor($user, $plan);
            }
        }

        $this->info('Plan:      ' . strtoupper($plan) . ' (' . $period . ')');
        $this->info('Price ID:  ' . $priceId);
        $this->info('Coupon:    ' . $coupon);
        $this->info('User:      ' . $email . ' (id ' . $user->id . ')');
        $this->info('Workspace: ' . ($workspace?->name ?? '(would create)') . ' (id ' . ($workspace?->id ?? '-') . ', plan ' . ($workspace?->plan ?? '-') . ', status ' . ($workspace?->subscription_status ?? '-') . ')');
        $this->newLine();

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        // Validate coupon against Stripe before doing anything mutational.
        $couponCheck = \Illuminate\Support\Facades\Http::withBasicAuth($stripeSecret, '')
            ->timeout(15)
            ->acceptJson()
            ->get('https://api.stripe.com/v1/coupons/' . urlencode($coupon));
        if (! $couponCheck->successful()) {
            $this->error('Coupon validation failed: ' . $couponCheck->body());
            return self::FAILURE;
        }
        $couponPayload = $couponCheck->json();
        $pctOff = $couponPayload['percent_off'] ?? null;
        $duration = $couponPayload['duration'] ?? null;
        $valid = $couponPayload['valid'] ?? false;
        if (! $valid) {
            $this->error("Coupon {$coupon} is not valid (expired or deleted).");
            return self::FAILURE;
        }
        $this->info("Coupon validated: {$pctOff}% off, duration {$duration}.");
        if ($pctOff !== 100 || $duration !== 'forever') {
            $this->warn("WARNING: coupon is not 100% off forever. It is {$pctOff}% off, duration {$duration}. Continuing anyway.");
            if (! $this->confirm('Proceed?', false)) {
                return self::SUCCESS;
            }
        }

        try {
            DB::transaction(function () use ($user, &$workspace, $plan, $priceId, $coupon, $force) {
                if (! $workspace) {
                    $workspace = $this->createWorkspaceFor($user, $plan);
                }

                // Bring workspace plan up to the requested tier.
                $workspace->forceFill([
                    'plan' => $plan,
                    'type' => $plan === 'solo' ? 'solo' : 'agency',
                ])->save();

                // Cancel any existing live subscription if --force.
                if ($workspace->subscribed('default')) {
                    if (! $force) {
                        $this->warn('Workspace already has an active subscription. Use --force to cancel + recreate.');
                        return;
                    }
                    $this->line('Cancelling existing subscription...');
                    $workspace->subscription('default')->cancelNow();
                }

                $this->line('Creating Stripe customer (or reusing existing)...');
                if (! $workspace->stripe_id) {
                    $workspace->createAsStripeCustomer([
                        'email' => $user->email,
                        'name' => $workspace->name,
                        'metadata' => [
                            'workspace_id' => $workspace->id,
                            'workspace_slug' => $workspace->slug,
                            'created_by' => 'founder:assign-subscription',
                        ],
                    ]);
                    $workspace->refresh();
                }

                $this->line('Creating subscription with coupon...');
                $subscription = $workspace
                    ->newSubscription('default', $priceId)
                    ->withCoupon($coupon)
                    ->skipTrial()
                    ->add();  // add() = create() with no payment method; works with $0 invoices.

                // Reflect into our app columns. The Stripe webhook will also
                // do this on invoice.payment_succeeded, but we don't want to
                // wait — set it now so the next request is correct.
                $workspace->forceFill([
                    'subscription_status' => 'active',
                    'trial_ends_at' => null,
                    'past_due_at' => null,
                    'canceled_at' => null,
                ])->save();

                $this->info('Subscription created: ' . $subscription->stripe_id);
            });
        } catch (\Throwable $e) {
            $this->error('Subscription create failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. ' . $email . ' is now on the ' . strtoupper($plan) . ' plan with the founder coupon.');
        $this->line('Verify: log in to /agency/billing — should show "Active subscription".');

        return self::SUCCESS;
    }

    private function createWorkspaceFor(User $user, string $plan): Workspace
    {
        $name = trim((string) $user->name) !== ''
            ? $user->name . "'s workspace"
            : 'Founder workspace';
        $slug = Str::slug($name);
        if (Workspace::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(6));
        }

        $ws = Workspace::create([
            'slug' => $slug,
            'name' => $name,
            'owner_id' => $user->id,
            'type' => $plan === 'solo' ? 'solo' : 'agency',
            'plan' => $plan,
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
        ]);

        WorkspaceMember::create([
            'workspace_id' => $ws->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        if (! $user->current_workspace_id) {
            $user->forceFill(['current_workspace_id' => $ws->id])->save();
        }

        return $ws;
    }
}
