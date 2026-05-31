<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * billing:create-signup-coupon — provision the launch signup discount in Stripe.
 *
 * Context: the public Checkout Session (App\Http\Controllers\BillingController::checkout)
 * already sends `allow_promotion_codes => true`, so customers see an "Add promotion
 * code" box at checkout. What was missing is an actual redeemable code. This command
 * creates a percent-off coupon + a customer-typeable Promotion Code so the box has
 * something to accept.
 *
 * Why a NEW coupon (not the dashboard's EIAAW-3097): EIAAW-3097 was created with
 * duration=once (first invoice only). The intended offer is 50% off the FIRST 3
 * MONTHS, which requires duration=repeating / duration_in_months=3. A Stripe coupon's
 * duration is IMMUTABLE after creation, so "once" can never become "3 months" — a new
 * coupon is the only correct path. The old EIAAW-3097 is left untouched (delete it in
 * the dashboard if you want, or leave it dormant — it has no promotion code so it is
 * unreachable at checkout).
 *
 * Secrets: this runs through Cashier::stripe(), which resolves STRIPE_SECRET via the
 * app's normal config chain (Infisical handle in production). No raw secret is handled
 * by the operator or printed. Run it on a host where the app's Stripe key resolves to
 * the LIVE account that holds your real checkout.
 *
 * Idempotent + verification-first:
 *   - Looks up the promotion code by its `code` string before creating anything; if it
 *     already exists, reports it and exits without duplicating.
 *   - Default is DRY-RUN. --apply is required to actually call Stripe.
 *   - After creating, retrieves the promotion code back and prints the resolved
 *     coupon terms so you can confirm 50% / 3 months / active.
 *
 * Examples:
 *   php artisan billing:create-signup-coupon                 # dry-run, shows the plan
 *   php artisan billing:create-signup-coupon --apply         # create it for real
 *   php artisan billing:create-signup-coupon --apply --max-redemptions=200 --expires=2026-06-30
 *   php artisan billing:create-signup-coupon --apply --first-time-only
 *   php artisan billing:create-signup-coupon --percent=50 --months=3 --code=FIRST3MONTHS
 *
 * Defaults match the agreed launch offer: 50% off / 3 months / code FIRST3MONTHS / MYR.
 */
class BillingCreateSignupCoupon extends Command
{
    protected $signature = 'billing:create-signup-coupon
                            {--apply : Actually create the coupon + promo code in Stripe (default is dry-run)}
                            {--percent=50 : Percent discount (1-100)}
                            {--months=3 : duration_in_months — how many billing cycles the discount applies}
                            {--code=FIRST3MONTHS : The promotion code customers type at checkout}
                            {--name= : Coupon display name (defaults to a descriptive label)}
                            {--max-redemptions= : Cap total redemptions of the PROMOTION CODE (omit = unlimited)}
                            {--expires= : Promotion code redeem-by date YYYY-MM-DD (omit = no expiry)}
                            {--first-time-only : Restrict the code to customers with no prior successful charge}';

    protected $description = 'Create the launch signup discount (percent-off, multi-month) + a customer-typeable promotion code in Stripe.';

    public function handle(): int
    {
        $percent = (int) $this->option('percent');
        $months  = (int) $this->option('months');
        $code    = strtoupper(trim((string) $this->option('code')));
        $currency = strtolower((string) config('billing.currency', 'myr'));
        $apply   = (bool) $this->option('apply');

        // -- Validate inputs (fail loud, before any Stripe call) ------------
        if ($percent < 1 || $percent > 100) {
            $this->error("--percent must be between 1 and 100 (got {$percent}).");
            return self::FAILURE;
        }
        if ($months < 1 || $months > 36) {
            $this->error("--months must be between 1 and 36 (got {$months}).");
            return self::FAILURE;
        }
        if ($code === '' || ! preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            $this->error("--code must be 3-40 chars, A-Z 0-9 _ - only (got '{$code}').");
            return self::FAILURE;
        }

        $name = (string) ($this->option('name')
            ?: sprintf('FIRST-SIGNUP — %d%% off first %d month%s', $percent, $months, $months === 1 ? '' : 's'));

        $maxRedemptions = $this->option('max-redemptions') !== null
            ? (int) $this->option('max-redemptions')
            : null;

        $expiresAt = null;
        if ($this->option('expires')) {
            $ts = strtotime((string) $this->option('expires').' 23:59:59');
            if ($ts === false) {
                $this->error("--expires must be a valid date YYYY-MM-DD (got '{$this->option('expires')}').");
                return self::FAILURE;
            }
            $expiresAt = $ts;
        }

        $firstTimeOnly = (bool) $this->option('first-time-only');

        // -- Show the plan --------------------------------------------------
        $this->info('Signup discount plan:');
        $this->table(['Field', 'Value'], [
            ['Coupon name', $name],
            ['Discount', "{$percent}% off"],
            ['Duration', "repeating — {$months} month(s)"],
            ['Currency', strtoupper($currency)],
            ['Promotion code', $code],
            ['Max redemptions', $maxRedemptions !== null ? (string) $maxRedemptions : 'unlimited'],
            ['Expires', $expiresAt ? date('Y-m-d', $expiresAt) : 'never'],
            ['First-time customers only', $firstTimeOnly ? 'yes' : 'no'],
        ]);

        $stripe = Cashier::stripe();

        // -- Idempotency: does this promotion code already exist? -----------
        try {
            $existing = $stripe->promotionCodes->all(['code' => $code, 'limit' => 1]);
        } catch (ApiErrorException $e) {
            $this->error('Stripe lookup failed: '.$e->getMessage());
            $this->line('Confirm the app is pointed at the right Stripe account (live vs test).');
            return self::FAILURE;
        }

        if (! empty($existing->data)) {
            $pc = $existing->data[0];
            $this->warn("Promotion code '{$code}' already exists (id: {$pc->id}).");
            $this->line('Nothing to create. Existing code resolves to:');
            $this->printPromotionCode($stripe, $pc->id);
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — nothing was created. Re-run with --apply to create it in Stripe.');
            return self::SUCCESS;
        }

        // -- Create the coupon ----------------------------------------------
        try {
            $coupon = $stripe->coupons->create([
                'percent_off'        => $percent,
                'duration'           => 'repeating',
                'duration_in_months' => $months,
                'name'               => $name,
                // currency is informational for a percent-off coupon, but we set
                // it so the dashboard groups it with the MYR catalog cleanly.
                'currency'           => $currency,
                'metadata'           => [
                    'purpose'    => 'signup-launch-discount',
                    'created_by' => 'billing:create-signup-coupon',
                ],
            ]);
        } catch (ApiErrorException $e) {
            $this->error('Coupon create failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info("Coupon created: {$coupon->id} ({$coupon->name}).");

        // -- Create the promotion code on that coupon -----------------------
        $promoArgs = [
            'coupon' => $coupon->id,
            'code'   => $code,
            'metadata' => [
                'purpose' => 'signup-launch-discount',
            ],
        ];
        if ($maxRedemptions !== null) {
            $promoArgs['max_redemptions'] = $maxRedemptions;
        }
        if ($expiresAt !== null) {
            $promoArgs['expires_at'] = $expiresAt;
        }
        if ($firstTimeOnly) {
            $promoArgs['restrictions'] = ['first_time_transaction' => true];
        }

        try {
            $promo = $stripe->promotionCodes->create($promoArgs);
        } catch (ApiErrorException $e) {
            $this->error('Promotion code create failed: '.$e->getMessage());
            $this->line("The coupon {$coupon->id} WAS created — re-running with the same --code will reuse it is NOT automatic; ");
            $this->line('either create the promotion code manually on that coupon in the dashboard, or delete the coupon and re-run.');
            return self::FAILURE;
        }

        Log::info('billing:create-signup-coupon created discount', [
            'coupon_id' => $coupon->id,
            'promo_id'  => $promo->id,
            'code'      => $code,
            'percent'   => $percent,
            'months'    => $months,
        ]);

        $this->info("Promotion code created: {$promo->id} (code: {$promo->code}).");
        $this->newLine();

        // -- Verify: read it back and print resolved terms ------------------
        $this->info('Verification — promotion code resolves to:');
        $this->printPromotionCode($stripe, $promo->id);

        $this->newLine();
        $this->info("Done. Customers can now type '{$code}' in the 'Add promotion code' box at checkout.");
        $this->line('Checkout already sends allow_promotion_codes=true (BillingController::checkout), so no app change is needed.');

        return self::SUCCESS;
    }

    /**
     * Retrieve a promotion code (with its coupon expanded) and print the
     * human-readable terms so the operator can confirm the discount is live
     * and correct.
     */
    private function printPromotionCode(\Stripe\StripeClient $stripe, string $promoId): void
    {
        try {
            $pc = $stripe->promotionCodes->retrieve($promoId, ['expand' => ['coupon']]);
        } catch (ApiErrorException $e) {
            $this->error('Could not retrieve promotion code for verification: '.$e->getMessage());
            return;
        }

        $coupon = $pc->coupon;
        $duration = $coupon->duration === 'repeating'
            ? "repeating — {$coupon->duration_in_months} month(s)"
            : $coupon->duration;

        $discount = $coupon->percent_off !== null
            ? "{$coupon->percent_off}% off"
            : (($coupon->amount_off ?? 0) / 100).' '.strtoupper((string) $coupon->currency).' off';

        $this->table(['Field', 'Value'], [
            ['Promotion code', $pc->code],
            ['Active', $pc->active ? 'yes' : 'NO'],
            ['Coupon id', $coupon->id],
            ['Coupon valid', $coupon->valid ? 'yes' : 'NO'],
            ['Discount', $discount],
            ['Duration', $duration],
            ['Times redeemed', (string) $pc->times_redeemed],
            ['Max redemptions', $pc->max_redemptions !== null ? (string) $pc->max_redemptions : 'unlimited'],
            ['Expires', $pc->expires_at ? date('Y-m-d', $pc->expires_at) : 'never'],
            ['First-time only', ! empty($pc->restrictions->first_time_transaction) ? 'yes' : 'no'],
        ]);
    }
}
