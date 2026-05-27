<?php

namespace App\Console\Commands;

use App\Models\StripePrice;
use App\Models\Workspace;
use App\Services\StripePriceCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

/**
 * billing:migrate-existing-prices — move existing customer subscriptions
 * from their old (cached) Stripe price IDs to the current price IDs derived
 * from config/billing.php.
 *
 * Why: when we raise the catalog price (eg. Solo RM 99 → RM 549), Stripe
 * locks the customer's existing subscription on the OLD price object until
 * we explicitly call Subscription::update with the new price. Without this
 * tool the new prices only apply to new signups — existing customers stay
 * on the legacy rate indefinitely.
 *
 * Stripe gives three proration_behavior choices when swapping a price:
 *   - create_prorations: standard — refund unused old, charge prorated new
 *   - none: no proration; next invoice = full new price
 *   - always_invoice: invoice the proration immediately
 * Default here is 'none' (cleanest customer story: "your next renewal is
 * the new price"). Operator can override with --proration=create_prorations
 * if you want the proration credit applied now.
 *
 * Default is DRY-RUN. --apply is required to actually call Stripe.
 *
 * Examples:
 *   php artisan billing:migrate-existing-prices                              # dry-run, all plans
 *   php artisan billing:migrate-existing-prices --plan=solo                  # one plan only
 *   php artisan billing:migrate-existing-prices --workspace=42               # one customer
 *   php artisan billing:migrate-existing-prices --apply                      # actually do it
 *   php artisan billing:migrate-existing-prices --apply --proration=create_prorations
 *
 * The new price ID is resolved via StripePriceCache::getOrCreate() so this
 * command is the right shape regardless of which price catalog you're
 * migrating from/to — works equally for the May 2026 rebase and any
 * future repricing.
 *
 * NEVER moves trial customers off trial — that would charge them immediately.
 * Skips workspaces on `trialing` status with a note; operator can re-run
 * after they convert.
 */
class BillingMigrateExistingPrices extends Command
{
    protected $signature = 'billing:migrate-existing-prices
                            {--apply : Actually update subscriptions (default is dry-run)}
                            {--plan= : Limit to one plan (solo|studio|agency)}
                            {--interval=month : Match subscriptions on this interval (month|year)}
                            {--workspace= : Limit to one workspace by ID}
                            {--proration=none : create_prorations | none | always_invoice}
                            {--limit=200 : Max subscriptions to touch per run}';

    protected $description = 'Move existing customer subscriptions onto the current Stripe price IDs from config/billing.php.';

    public function handle(StripePriceCache $cache): int
    {
        $apply = (bool) $this->option('apply');
        $planFilter = $this->option('plan');
        $interval = (string) $this->option('interval');
        $wsFilter = $this->option('workspace') ? (int) $this->option('workspace') : null;
        $proration = (string) $this->option('proration');
        $limit = max(1, (int) $this->option('limit'));

        if (! in_array($proration, ['none', 'create_prorations', 'always_invoice'], true)) {
            $this->error("--proration must be one of: none, create_prorations, always_invoice");
            return self::FAILURE;
        }
        if (! in_array($interval, ['month', 'year'], true)) {
            $this->error("--interval must be month or year");
            return self::FAILURE;
        }

        $plans = $planFilter ? [$planFilter] : ['solo', 'studio', 'agency'];

        $this->info('Resolving target price IDs from config/billing.php...');
        $targetPriceIdByPlan = [];
        foreach ($plans as $plan) {
            if (! config('billing.plans.' . $plan)) {
                $this->error("Unknown plan: {$plan}");
                return self::FAILURE;
            }
            try {
                $targetPriceIdByPlan[$plan] = $cache->getOrCreate($plan, $interval);
                $cents = StripePriceCache::unitAmountFor(config('billing.plans.' . $plan), $interval);
                $this->line(sprintf('  %s/%s → %s (RM %s)', $plan, $interval, $targetPriceIdByPlan[$plan], number_format($cents / 100)));
            } catch (\Throwable $e) {
                $this->error("Failed to resolve {$plan}/{$interval}: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Scanning workspaces with subscriptions...');

        $q = Workspace::query()
            ->whereIn('plan', $plans)
            ->whereNotNull('stripe_customer_id');
        if ($wsFilter) $q->where('id', $wsFilter);

        $workspaces = $q->limit($limit)->get();

        $stats = ['scanned' => 0, 'on_target_already' => 0, 'trialing_skipped' => 0,
                  'no_sub_skipped' => 0, 'would_migrate' => 0, 'migrated' => 0, 'errors' => 0];

        $stripe = Cashier::stripe();

        foreach ($workspaces as $ws) {
            $stats['scanned']++;
            $targetPriceId = $targetPriceIdByPlan[$ws->plan] ?? null;
            if (! $targetPriceId) continue;

            // Trialing customers must NOT be migrated — Subscription::update
            // with a new price on a trial cancels the trial unless the
            // trial_end is explicitly preserved. Skip cleanly and report.
            if ($ws->subscription_status === 'trialing') {
                $stats['trialing_skipped']++;
                $this->line(sprintf('WS#%d (%s, %s) skipped — trialing', $ws->id, $ws->slug, $ws->plan));
                continue;
            }

            try {
                $stripeSub = $stripe->subscriptions->all([
                    'customer' => $ws->stripe_customer_id,
                    'status' => 'active',
                    'limit' => 1,
                ]);
                $sub = $stripeSub->data[0] ?? null;
                if (! $sub) {
                    $stats['no_sub_skipped']++;
                    $this->line(sprintf('WS#%d (%s) skipped — no active Stripe subscription', $ws->id, $ws->slug));
                    continue;
                }

                $items = $sub->items?->data ?? [];
                if (empty($items)) {
                    $stats['errors']++;
                    $this->warn(sprintf('WS#%d sub has no items — skipping', $ws->id));
                    continue;
                }
                $item = $items[0];
                $currentPriceId = $item->price?->id ?? '?';

                if ($currentPriceId === $targetPriceId) {
                    $stats['on_target_already']++;
                    $this->line(sprintf('WS#%d (%s, %s) already on target price', $ws->id, $ws->slug, $ws->plan));
                    continue;
                }

                $this->info(sprintf(
                    'WS#%d (%s, %s) → migrate %s → %s [proration=%s]',
                    $ws->id, $ws->slug, $ws->plan, $currentPriceId, $targetPriceId, $proration,
                ));

                if (! $apply) {
                    $stats['would_migrate']++;
                    continue;
                }

                $stripe->subscriptions->update($sub->id, [
                    'items' => [[
                        'id' => $item->id,
                        'price' => $targetPriceId,
                    ]],
                    'proration_behavior' => $proration,
                    'metadata' => array_merge(
                        method_exists($sub->metadata ?? null, 'toArray') ? $sub->metadata->toArray() : [],
                        ['migrated_from_price' => $currentPriceId, 'migrated_at' => now()->toIso8601String()],
                    ),
                ]);
                $stats['migrated']++;

                Log::info('billing:migrate-existing-prices migrated subscription', [
                    'workspace_id' => $ws->id,
                    'plan' => $ws->plan,
                    'from' => $currentPriceId,
                    'to' => $targetPriceId,
                    'proration' => $proration,
                ]);

            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error(sprintf('WS#%d failed: %s', $ws->id, $e->getMessage()));
                Log::error('billing:migrate-existing-prices failed for workspace', [
                    'workspace_id' => $ws->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('──────── Summary ────────');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-22s %d', $k . ':', $v));
        }
        if (! $apply) {
            $this->warn('DRY-RUN — nothing changed in Stripe. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
