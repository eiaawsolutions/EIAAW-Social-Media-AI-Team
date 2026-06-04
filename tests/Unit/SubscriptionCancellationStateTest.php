<?php

namespace Tests\Unit;

use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the cancellation lifecycle state machine on the Workspace model:
 * hasActiveAccess() across the grace matrix, onCancellationGracePeriod(),
 * inReadOnlyGrace(), readOnlyGraceEndsAt(), cancellationState().
 *
 * DB-FREE by design — the local .env DB is the stale/empty prod-pointed
 * Postgres ([[support_chatbot]] caveat), so these build in-memory Workspace
 * instances and stub the one DB-touching dependency (the Cashier
 * subscription's grace-period flag) via a tiny subclass. Pure logic only.
 */
class SubscriptionCancellationStateTest extends TestCase
{
    /** Build an unsaved workspace with a controllable grace-period flag. */
    private function workspace(array $attrs, bool $onGrace = false): Workspace
    {
        $ws = new class extends Workspace {
            public bool $stubOnGrace = false;
            public function onCancellationGracePeriod(): bool
            {
                return $this->stubOnGrace;
            }
        };
        foreach ($attrs as $k => $v) {
            $ws->{$k} = $v;
        }
        $ws->stubOnGrace = $onGrace;

        return $ws;
    }

    public function test_active_subscription_has_access_and_active_state(): void
    {
        $ws = $this->workspace(['plan' => 'solo', 'subscription_status' => 'active']);

        $this->assertTrue($ws->hasActiveAccess());
        $this->assertSame('active', $ws->cancellationState());
        $this->assertNull($ws->readOnlyGraceEndsAt());
        $this->assertFalse($ws->inReadOnlyGrace());
    }

    public function test_eiaaw_internal_always_has_access(): void
    {
        $ws = $this->workspace(['plan' => 'eiaaw_internal', 'subscription_status' => 'active']);
        $this->assertTrue($ws->hasActiveAccess());
    }

    public function test_cancel_at_period_end_keeps_access_during_grace(): void
    {
        // Stripe leaves stripe_status active during the grace window, so our
        // denormalised subscription_status is still 'active' AND the Cashier
        // grace flag is true. Either signal alone grants access.
        $ws = $this->workspace(
            ['plan' => 'solo', 'subscription_status' => 'active'],
            onGrace: true,
        );

        $this->assertTrue($ws->hasActiveAccess());
        $this->assertSame('grace_period', $ws->cancellationState());
        $this->assertFalse($ws->inReadOnlyGrace());
    }

    public function test_canceled_within_30_day_window_is_read_only_grace_no_access(): void
    {
        $ws = $this->workspace([
            'plan' => 'solo',
            'subscription_status' => 'canceled',
            'canceled_at' => Carbon::now()->subDays(5),
        ], onGrace: false);

        $this->assertFalse($ws->hasActiveAccess(), 'read-only grace must NOT grant panel access');
        $this->assertTrue($ws->inReadOnlyGrace());
        $this->assertSame('read_only_grace', $ws->cancellationState());
        $this->assertTrue($ws->readOnlyGraceEndsAt()->isFuture());
        // 30-day window from canceled_at.
        $this->assertSame(
            Carbon::now()->subDays(5)->addDays(Workspace::READ_ONLY_GRACE_DAYS)->toDateString(),
            $ws->readOnlyGraceEndsAt()->toDateString(),
        );
    }

    public function test_canceled_past_30_days_is_expired(): void
    {
        $ws = $this->workspace([
            'plan' => 'solo',
            'subscription_status' => 'canceled',
            'canceled_at' => Carbon::now()->subDays(Workspace::READ_ONLY_GRACE_DAYS + 1),
        ], onGrace: false);

        $this->assertFalse($ws->hasActiveAccess());
        $this->assertFalse($ws->inReadOnlyGrace());
        $this->assertSame('expired', $ws->cancellationState());
    }

    public function test_suspended_workspace_has_no_access_and_not_in_read_only_grace(): void
    {
        $ws = $this->workspace([
            'plan' => 'solo',
            'subscription_status' => 'canceled',
            'canceled_at' => Carbon::now()->subDays(2),
            'suspended_at' => Carbon::now(),
        ], onGrace: false);

        $this->assertFalse($ws->hasActiveAccess());
        $this->assertFalse($ws->inReadOnlyGrace(), 'a suspended (soft-deleted) workspace is past read-only grace');
    }

    public function test_past_due_within_grace_has_access(): void
    {
        $ws = $this->workspace([
            'plan' => 'solo',
            'subscription_status' => 'past_due',
            'past_due_at' => Carbon::now()->subDay(),
        ]);
        $this->assertTrue($ws->hasActiveAccess());
    }

    public function test_past_due_beyond_grace_has_no_access(): void
    {
        $ws = $this->workspace([
            'plan' => 'solo',
            'subscription_status' => 'past_due',
            'past_due_at' => Carbon::now()->subDays(Workspace::PAST_DUE_GRACE_DAYS + 1),
        ]);
        $this->assertFalse($ws->hasActiveAccess());
    }

    public function test_trialing_in_future_has_access_expired_does_not(): void
    {
        $live = $this->workspace([
            'plan' => 'solo', 'subscription_status' => 'trialing',
            'trial_ends_at' => Carbon::now()->addDays(3),
        ]);
        $dead = $this->workspace([
            'plan' => 'solo', 'subscription_status' => 'trialing',
            'trial_ends_at' => Carbon::now()->subDay(),
        ]);

        $this->assertTrue($live->hasActiveAccess());
        $this->assertFalse($dead->hasActiveAccess());
    }

    public function test_grace_constants_match_documented_policy(): void
    {
        // Locks the documented "3-day past-due grace" + "30-day read-only grace".
        $this->assertSame(30, Workspace::READ_ONLY_GRACE_DAYS);
        $this->assertSame(3, Workspace::PAST_DUE_GRACE_DAYS);
    }
}
