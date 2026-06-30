<?php

namespace App\Console\Commands;

use App\Services\Imagery\FalAiClient;
use App\Services\Imagery\MediaGenerationAlerter;
use Illuminate\Console\Command;

/**
 * PROACTIVE FAL low-balance warning. Reads the FAL.AI credit balance and emails
 * the admin when it drops below the threshold (default $5) — BEFORE the prepaid
 * balance hits $0 and locks the account, which silently strands every draft as
 * compliance_failed (the failure mode that went unnoticed for ~3 weeks).
 *
 * Complements the REACTIVE lockout alert (fired from the Designer/Video catch
 * sites): this one warns while there's still time to top up without an outage.
 *
 * Reading the balance needs an ADMIN-scoped FAL key — the inference key is
 * 403-denied on the billing endpoint. Until services.fal.admin_api_key is
 * provisioned in Infisical, fetchAccountBalance() returns null and this command
 * no-ops loudly (logs why) rather than alerting. Disable entirely by setting
 * media.alerts.low_balance_threshold to 0.
 *
 * Throttling lives in MediaGenerationAlerter (one low-balance email per window),
 * so running hourly does not spam — it just keeps the warning fresh.
 *
 * Usage:
 *   php artisan fal:check-balance
 *   php artisan fal:check-balance --dry-run   # report only, never email
 */
class FalCheckBalance extends Command
{
    protected $signature = 'fal:check-balance
                            {--dry-run : Report the balance without sending an alert}';

    protected $description = 'Proactive: warn the admin when the FAL.AI credit balance drops below the threshold.';

    public function handle(MediaGenerationAlerter $alerter): int
    {
        $threshold = (float) config('media.alerts.low_balance_threshold', 5.0);
        if ($threshold <= 0) {
            $this->info('Low-balance warning disabled (threshold <= 0).');

            return self::SUCCESS;
        }

        $balance = FalAiClient::fetchAccountBalance();

        if ($balance === null) {
            // Unreadable — almost always "no admin key provisioned yet" (the
            // inference key is billing-403). Not an error; just can't warn.
            $this->warn('FAL balance unavailable (no admin key, or billing endpoint denied). '
                .'Provision services.fal.admin_api_key to enable the proactive warning.');

            return self::SUCCESS;
        }

        $this->line(sprintf('FAL balance: $%.2f (threshold $%.2f)', $balance, $threshold));

        if ($balance >= $threshold) {
            $this->info('Balance healthy — no alert.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn(sprintf('[dry-run] Would alert: balance $%.2f is below $%.2f.', $balance, $threshold));

            return self::SUCCESS;
        }

        $alerter->lowBalance($balance, $threshold);
        $this->warn(sprintf('Low-balance alert dispatched (balance $%.2f < $%.2f).', $balance, $threshold));

        return self::SUCCESS;
    }
}
