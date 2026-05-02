<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * accounts:audit — read-only inventory of users, workspaces, Cashier
 * subscriptions, brands, and webhook events. Used to confirm prod state
 * before any destructive operation (purge, founder-account creation,
 * launch).
 *
 * No writes. Safe to run any time.
 */
class AuditAccounts extends Command
{
    protected $signature = 'accounts:audit';

    protected $description = 'Read-only inventory of users, workspaces, subscriptions, and webhook events.';

    public function handle(): int
    {
        $this->info('=== USERS (' . User::count() . ' total) ===');
        $users = User::with('ownedWorkspaces')->orderBy('id')->get();
        $rows = $users->map(fn (User $u) => [
            $u->id,
            substr((string) $u->name, 0, 24),
            $u->email,
            $u->is_super_admin ? 'Y' : 'N',
            $u->ownedWorkspaces->count(),
            $u->created_at?->toDateTimeString() ?? '-',
        ])->all();
        $this->table(['id', 'name', 'email', 'super_admin', 'owned_ws', 'created_at'], $rows);

        $this->newLine();
        $this->info('=== WORKSPACES (' . Workspace::count() . ' total) ===');
        $workspaces = Workspace::with('owner')->orderBy('id')->get();
        $wsRows = $workspaces->map(function (Workspace $w) {
            $hasCashierSub = DB::table('subscriptions')->where('workspace_id', $w->id)->exists();
            return [
                $w->id,
                substr((string) $w->slug, 0, 28),
                $w->plan,
                $w->subscription_status,
                $w->type,
                $w->owner->email ?? '(no owner)',
                empty($w->stripe_customer_id) ? 'N' : 'Y',
                $hasCashierSub ? 'Y' : 'N',
                $w->trial_ends_at?->toDateString() ?? '-',
            ];
        })->all();
        $this->table(
            ['id', 'slug', 'plan', 'status', 'type', 'owner', 'stripe_cust', 'cashier_sub', 'trial_ends'],
            $wsRows,
        );

        $this->newLine();
        $this->info('=== CASHIER SUBSCRIPTIONS (' . DB::table('subscriptions')->count() . ' total) ===');
        $subs = DB::table('subscriptions')->orderBy('id')->get();
        $subRows = collect($subs)->map(fn ($s) => [
            $s->id,
            $s->workspace_id,
            $s->type,
            substr($s->stripe_id, 0, 24),
            $s->stripe_status,
            substr((string) ($s->stripe_price ?? '-'), 0, 24),
            $s->trial_ends_at ?? '-',
            $s->ends_at ?? '-',
        ])->all();
        $this->table(
            ['id', 'ws', 'type', 'stripe_id', 'status', 'price', 'trial_ends', 'ends_at'],
            $subRows,
        );

        $this->newLine();
        $this->info('=== BRANDS (' . Brand::count() . ' total) ===');
        $brandRows = Brand::with('workspace')->orderBy('id')->get()->map(fn (Brand $b) => [
            $b->id,
            substr((string) $b->slug, 0, 28),
            $b->workspace->slug ?? '(orphan)',
        ])->all();
        $this->table(['id', 'slug', 'workspace'], $brandRows);

        $this->newLine();
        $this->info('=== SUBSCRIPTION EVENTS (' . SubscriptionEvent::count() . ' total) ===');
        $events = SubscriptionEvent::orderByDesc('id')->limit(10)->get();
        $eventRows = $events->map(fn (SubscriptionEvent $e) => [
            $e->id,
            substr($e->stripe_event_id, 0, 24),
            $e->event_type,
            $e->workspace_id ?? '-',
            $e->processed_at?->toDateTimeString() ?? '-',
            $e->processing_error ? 'YES' : 'no',
            $e->created_at?->toDateTimeString(),
        ])->all();
        $this->table(
            ['id', 'stripe_event_id', 'type', 'ws', 'processed_at', 'err', 'created_at'],
            $eventRows,
        );

        $this->newLine();
        $this->info('=== ORPHAN CHECK ===');
        $usersWithoutWs = User::doesntHave('ownedWorkspaces')
            ->where('is_super_admin', false)
            ->get();
        $wsWithoutSub = Workspace::whereNotIn('plan', ['eiaaw_internal'])
            ->whereDoesntHave('subscriptions')
            ->get();

        $this->line('Users without an owned workspace (and not super-admin): ' . $usersWithoutWs->count());
        foreach ($usersWithoutWs as $u) {
            $this->line('  - ' . $u->email);
        }
        $this->line('Workspaces without ANY Cashier subscription (excl. eiaaw_internal): ' . $wsWithoutSub->count());
        foreach ($wsWithoutSub as $w) {
            $this->line('  - #' . $w->id . ' ' . $w->slug . ' owner=' . ($w->owner->email ?? '?'));
        }

        return self::SUCCESS;
    }
}
