<?php

namespace App\Filament\Resources\WorkspaceSubscriptions;

use App\Filament\Resources\WorkspaceSubscriptions\Pages\ManageWorkspaceSubscriptions;
use App\Models\AuditLogEntry;
use App\Models\Workspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * HQ SUBSCRIPTIONS — cross-tenant subscription administration for support,
 * offboarding, and dispute handling. Lets a super-admin cancel-at-period-end,
 * cancel-now, or reactivate a customer's subscription on their behalf, with
 * every action recorded in the immutable audit_log (which the raw Stripe
 * dashboard cannot do).
 *
 * Same access posture and rationale as ClientBrandResource: this lives OUTSIDE
 * App\Filament\Agency so the TenantIsolationGuardTest does not apply — seeing
 * every tenant is the point, and it is safe because of TWO gates:
 *   1. Panel boundary — User::canAccessPanel('admin') => is_super_admin.
 *   2. Resource boundary — canAccess()/canViewAny() re-check is_super_admin.
 *
 * EIAAW internal workspaces are excluded from the query — they are never billed.
 */
class WorkspaceSubscriptionResource extends Resource
{
    protected static ?string $model = Workspace::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;
    protected static ?string $navigationLabel = 'Subscriptions';
    protected static ?string $modelLabel = 'Subscription';
    protected static ?string $pluralModelLabel = 'Subscriptions';
    protected static \UnitEnum|string|null $navigationGroup = 'Clients';
    protected static ?int $navigationSort = 2;
    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Workspace')
                    ->description(fn (Workspace $r) => $r->owner?->email)
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('plan')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => config("billing.plans.{$state}.name", ucfirst($state))),
                Tables\Columns\TextColumn::make('subscription_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active', 'trialing' => 'success',
                        'past_due' => 'warning',
                        'canceled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('cancellation_state')
                    ->label('Lifecycle')
                    ->state(fn (Workspace $r) => $r->cancellationState())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'grace_period' => 'warning',
                        'read_only_grace' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),
                Tables\Columns\TextColumn::make('grace_ends')
                    ->label('Access until')
                    ->state(fn (Workspace $r) => $r->cancellationGraceEndsAt()?->format('j M Y') ?? '—'),
                Tables\Columns\TextColumn::make('readonly_ends')
                    ->label('Data kept until')
                    ->state(fn (Workspace $r) => $r->readOnlyGraceEndsAt()?->format('j M Y') ?? '—'),
                Tables\Columns\TextColumn::make('suspended_at')
                    ->label('Suspended')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subscription_status')
                    ->options([
                        'active' => 'Active',
                        'trialing' => 'Trialing',
                        'past_due' => 'Past due',
                        'canceled' => 'Canceled',
                        'none' => 'None',
                    ]),
            ])
            ->recordActions([
                // Cancel at period end — the default, customer-friendly path.
                Action::make('cancel')
                    ->label('Cancel (period end)')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Workspace $r) => "Cancel {$r->name} at period end")
                    ->modalDescription('The customer keeps full access until the end of the period they have paid for, then the subscription stops. No refund is issued.')
                    ->visible(fn (Workspace $r) => self::hasCancellableSub($r) && $r->cancellationState() === 'active')
                    ->action(fn (Workspace $r) => self::cancelAtPeriodEnd($r)),

                // Cancel now — immediate stop. Support/abuse offboarding only.
                Action::make('cancelNow')
                    ->label('Cancel NOW')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Workspace $r) => "Cancel {$r->name} immediately")
                    ->modalDescription('Access ends immediately and the subscription is terminated now. Use only for offboarding / abuse — the customer loses the remainder of the period they paid for. No automatic refund.')
                    ->visible(fn (Workspace $r) => self::hasCancellableSub($r))
                    ->action(fn (Workspace $r) => self::cancelNow($r)),

                // Reactivate — only while cancel-at-period-end is pending.
                Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Workspace $r) => "Reactivate {$r->name}")
                    ->modalDescription('Clears the scheduled cancellation; billing resumes on the normal renewal date.')
                    ->visible(fn (Workspace $r) => $r->cancellationState() === 'grace_period')
                    ->action(fn (Workspace $r) => self::reactivate($r)),
            ])
            ->defaultSort('subscription_status')
            ->emptyStateHeading('No customer subscriptions yet');
    }

    private static function hasCancellableSub(Workspace $r): bool
    {
        return (bool) $r->subscription('default')?->active();
    }

    private static function cancelAtPeriodEnd(Workspace $r): void
    {
        self::runStripe($r, fn () => $r->subscription('default')->cancel(), 'subscription.cancel_requested', [
            'mode' => 'period_end',
            'ends_at' => $r->subscription('default')?->ends_at?->toIso8601String(),
        ], 'Subscription set to cancel at period end.');
    }

    private static function cancelNow(Workspace $r): void
    {
        self::runStripe($r, fn () => $r->subscription('default')->cancelNow(), 'subscription.cancel_now', [
            'mode' => 'immediate',
        ], 'Subscription cancelled immediately.');
    }

    private static function reactivate(Workspace $r): void
    {
        $sub = $r->subscription('default');
        if (! $sub || ! $sub->onGracePeriod()) {
            self::fail('This subscription is not in a reactivatable state.');
            return;
        }
        self::runStripe($r, fn () => $sub->resume(), 'subscription.resumed', [
            'by' => 'hq',
        ], 'Subscription reactivated.');
    }

    /**
     * Run a Stripe mutation, audit it, notify — all error-guarded. Every HQ
     * action lands an immutable audit_log row with the acting super-admin as
     * the actor (accountability the Stripe dashboard can't give us).
     */
    private static function runStripe(Workspace $r, callable $op, string $auditAction, array $context, string $successBody): void
    {
        // Defense-in-depth: re-verify super-admin at ACTION time, not just at
        // resource-load time. canAccess()/canViewAny() gate the table render, and
        // ->visible() gates by subscription state — but a privilege revoked between
        // page-load and click would otherwise still let the stale session mutate
        // Stripe. This is the single chokepoint all three HQ actions flow through,
        // so guarding here covers cancel / cancelNow / reactivate. It also keeps the
        // hardcoded actor_type='super_admin' audit entry below honest.
        if (! auth()->user()?->is_super_admin) {
            self::fail('You no longer have permission to manage subscriptions.');
            return;
        }

        $before = ['subscription_status' => $r->subscription_status];

        try {
            $op();
        } catch (\Throwable $e) {
            Log::error("HQ {$auditAction} failed", ['workspace_id' => $r->id, 'error' => $e->getMessage()]);
            self::fail('Stripe call failed: ' . $e->getMessage());
            return;
        }

        AuditLogEntry::create([
            'workspace_id' => $r->id,
            'actor_user_id' => auth()->id(),
            'actor_type' => 'super_admin',
            'action' => $auditAction,
            'subject_type' => Workspace::class,
            'subject_id' => $r->id,
            'before' => $before,
            'context' => $context,
            'occurred_at' => now(),
        ]);

        Notification::make()->title('Done')->body($successBody)->success()->send();
    }

    private static function fail(string $body): void
    {
        Notification::make()->title('Action failed')->body($body)->danger()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWorkspaceSubscriptions::route('/'),
        ];
    }

    /**
     * Cross-tenant by design — every NON-internal workspace, with owner +
     * latest subscription eager-loaded. NO workspace_id constraint: the
     * panel-level super-admin gate is what makes the all-tenants view safe.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('plan', '!=', 'eiaaw_internal')
            ->with(['owner', 'subscriptions']);
    }
}
