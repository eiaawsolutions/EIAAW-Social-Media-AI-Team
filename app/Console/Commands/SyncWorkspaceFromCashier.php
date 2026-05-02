<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * workspaces:sync-from-cashier — invokes the same sync logic the
 * StripeWebhookController uses to derive workspace.subscription_status
 * + workspace.trial_ends_at from the Cashier subscriptions row, against
 * a single workspace.
 *
 * Used when:
 *   - A webhook event was processed by an old buggy handler that didn't
 *     correctly populate workspace columns. The event is now marked
 *     processed_at=now in subscription_events (idempotency block), so
 *     resending it from Stripe dashboard is a no-op. This command runs
 *     the new sync logic directly without re-firing the webhook.
 *   - A workspace's denormalised columns drifted out of sync from the
 *     Cashier row for any other reason and needs repair.
 *
 * Idempotent. The Cashier row is the source of truth (Cashier writes it
 * from the actual Stripe payload). Re-running rewrites the workspace
 * columns to whatever the Cashier row says.
 */
class SyncWorkspaceFromCashier extends Command
{
    protected $signature = 'workspaces:sync-from-cashier {--id=}';

    protected $description = 'Sync a workspace\'s subscription columns from its Cashier subscription row.';

    public function handle(): int
    {
        $w = Workspace::find((int) $this->option('id'));
        if (! $w) {
            $this->error('--id is required, and workspace must exist');
            return self::FAILURE;
        }

        $sub = $w->subscriptions()->where('type', 'default')->orderByDesc('id')->first();
        if (! $sub) {
            $this->error("No Cashier subscription for workspace {$w->id} ({$w->slug}).");
            return self::FAILURE;
        }

        $newStatus = match ($sub->stripe_status) {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due' => 'past_due',
            'canceled', 'unpaid', 'incomplete_expired' => 'canceled',
            default => $w->subscription_status,
        };

        $updates = [
            'subscription_status' => $newStatus,
            'trial_ends_at' => $sub->trial_ends_at,
            'past_due_at' => $newStatus === 'past_due' ? ($w->past_due_at ?? now()) : null,
            'canceled_at' => $newStatus === 'canceled' ? ($w->canceled_at ?? now()) : null,
        ];

        if ($w->isSuspended && in_array($newStatus, ['trialing', 'active'], true)) {
            $updates['suspended_at'] = null;
            $updates['suspended_reason'] = null;
        }

        $this->info("Workspace #{$w->id} ({$w->slug}) — current state:");
        $this->line('  subscription_status: ' . $w->subscription_status);
        $this->line('  trial_ends_at:       ' . ($w->trial_ends_at?->toIso8601String() ?? '(NULL)'));
        $this->line('  hasActiveAccess():   ' . ($w->hasActiveAccess() ? 'true' : 'false'));
        $this->newLine();
        $this->info('Cashier sub (source of truth):');
        $this->line('  stripe_status:       ' . $sub->stripe_status);
        $this->line('  trial_ends_at:       ' . ($sub->trial_ends_at?->toIso8601String() ?? '(NULL)'));
        $this->newLine();

        $w->update($updates);
        $w->refresh();

        $this->info('Done. New state:');
        $this->line('  subscription_status: ' . $w->subscription_status);
        $this->line('  trial_ends_at:       ' . ($w->trial_ends_at?->toIso8601String() ?? '(NULL)'));
        $this->line('  hasActiveAccess():   ' . ($w->hasActiveAccess() ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
