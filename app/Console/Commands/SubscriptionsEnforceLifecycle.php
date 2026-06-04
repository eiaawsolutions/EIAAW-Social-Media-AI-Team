<?php

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the back-half subscription lifecycle the webhook flags but doesn't
 * itself act on — closes follow-ups item #3. Runs daily. Dry-run by default;
 * --apply commits. Idempotent: re-running never double-applies a transition.
 *
 * Three deterministic transitions:
 *
 *   1. PAST_DUE > grace  → suspend (suspended_at + reason='payment_failed').
 *      hasActiveAccess() already denies access after PAST_DUE_GRACE_DAYS; this
 *      makes the suspension durable + audited so the customer's state is
 *      explicit, not merely time-derived.
 *
 *   2. CANCELED, within read-only grace → no action. Panel is already locked
 *      (hasActiveAccess() false), data preserved. Reported for visibility only.
 *
 *   3. CANCELED, past READ_ONLY_GRACE_DAYS → SOFT-DELETE (suspended_at +
 *      reason='cancellation_grace_expired'). NEVER a physical delete: audit_log
 *      is append-only and the workspace soft-delete pattern preserves the row
 *      indefinitely (followups memory). De-provisioning the Metricool/Blotato
 *      handle is a separate operator step — flagged in the output, not automated.
 *
 * Every committed transition writes an immutable audit_log row. EIAAW internal
 * and currently-active subscribers are NEVER touched.
 */
class SubscriptionsEnforceLifecycle extends Command
{
    protected $signature = 'subscriptions:enforce-lifecycle
        {--apply : Actually perform the transitions (default is dry-run)}
        {--force : Skip the interactive confirmation prompt (required for non-interactive use, e.g. cron / railway ssh)}';

    protected $description = 'Suspend past-due-beyond-grace workspaces and soft-delete cancelled workspaces past their 30-day read-only grace. Never touches eiaaw_internal or active subscribers.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'APPLY MODE: transitions will be committed.'
            : 'DRY-RUN: nothing will change. Pass --apply to commit.');
        $this->newLine();

        // Only non-internal workspaces with a real subscription can transition.
        // eiaaw_internal is excluded outright; active subscribers fall through
        // every gate below and are left alone.
        $workspaces = Workspace::with('owner')
            ->where('plan', '!=', 'eiaaw_internal')
            ->get();

        $toSuspendPastDue = [];
        $toSoftDelete = [];
        $inReadOnlyGrace = [];

        foreach ($workspaces as $w) {
            // (1) past_due beyond grace, not already suspended.
            if ($w->subscription_status === 'past_due'
                && ! $w->isSuspended
                && $w->past_due_at !== null
                && $w->past_due_at->copy()->addDays(Workspace::PAST_DUE_GRACE_DAYS)->isPast()
            ) {
                $toSuspendPastDue[] = $w;
                continue;
            }

            // (2) + (3) canceled.
            if ($w->subscription_status === 'canceled' && $w->canceled_at !== null) {
                // Still inside the cancel-at-period-end window? Skip entirely —
                // they keep access; not a canceled-grace case yet.
                if ($w->onCancellationGracePeriod()) {
                    continue;
                }

                if ($w->isSuspended) {
                    continue; // already soft-deleted
                }

                if ($w->readOnlyGraceEndsAt()?->isPast()) {
                    $toSoftDelete[] = $w; // (3)
                } else {
                    $inReadOnlyGrace[] = $w; // (2) — report only
                }
            }
        }

        $this->report('Past-due → suspend', $toSuspendPastDue);
        $this->report('Canceled → soft-delete (grace expired)', $toSoftDelete);
        $this->report('Canceled → in read-only grace (no action)', $inReadOnlyGrace, action: false);

        $actionable = count($toSuspendPastDue) + count($toSoftDelete);
        if ($actionable === 0) {
            $this->info('No actionable transitions.');
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to commit the ' . $actionable . ' transition(s) above.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Commit {$actionable} transition(s)?", false)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $suspended = 0;
        foreach ($toSuspendPastDue as $w) {
            $this->transition($w, 'payment_failed', 'subscription.suspended_past_due');
            $suspended++;
        }

        $softDeleted = 0;
        foreach ($toSoftDelete as $w) {
            $this->transition($w, 'cancellation_grace_expired', 'subscription.soft_deleted');
            $softDeleted++;
            $this->line("  ⚠ Workspace #{$w->id} ({$w->slug}) soft-deleted — de-provision its Metricool/Blotato handle manually if no longer needed.");
        }

        $this->newLine();
        $this->info("Done. Suspended (past-due): {$suspended}. Soft-deleted (cancellation-grace expired): {$softDeleted}.");

        return self::SUCCESS;
    }

    /**
     * Apply a soft-delete-style suspension + write the immutable audit row.
     * Soft-delete = set suspended_at; the workspace row, its data, and the
     * audit log are all preserved. Never a physical delete.
     */
    private function transition(Workspace $w, string $reason, string $auditAction): void
    {
        $before = [
            'subscription_status' => $w->subscription_status,
            'suspended_at' => optional($w->suspended_at)->toIso8601String(),
            'canceled_at' => optional($w->canceled_at)->toIso8601String(),
        ];

        $w->update([
            'suspended_at' => now(),
            'suspended_reason' => $reason,
        ]);

        AuditLogEntry::create([
            'workspace_id' => $w->id,
            'actor_user_id' => null,
            'actor_type' => 'system',
            'action' => $auditAction,
            'subject_type' => Workspace::class,
            'subject_id' => $w->id,
            'before' => $before,
            'after' => [
                'suspended_at' => $w->suspended_at?->toIso8601String(),
                'suspended_reason' => $reason,
            ],
            'context' => ['command' => 'subscriptions:enforce-lifecycle'],
            'occurred_at' => now(),
        ]);

        Log::info("subscriptions:enforce-lifecycle {$auditAction} workspace {$w->slug} (#{$w->id}), reason={$reason}.");
    }

    /**
     * @param array<int, Workspace> $workspaces
     */
    private function report(string $heading, array $workspaces, bool $action = true): void
    {
        $this->line("<options=bold>{$heading}</>: " . count($workspaces));
        if (empty($workspaces)) {
            return;
        }
        $this->table(
            ['id', 'slug', 'plan', 'status', 'canceled_at', 'grace_ends', 'owner'],
            array_map(fn (Workspace $w) => [
                $w->id,
                $w->slug,
                $w->plan,
                $w->subscription_status,
                optional($w->canceled_at)->toDateString() ?? '-',
                optional($w->readOnlyGraceEndsAt())->toDateString() ?? '-',
                $w->owner->email ?? '(no owner)',
            ], $workspaces),
        );
        $this->newLine();
    }
}
