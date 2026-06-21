<?php

namespace App\Filament\Agency\Pages;

use App\Mail\SubscriptionCancelled;
use App\Models\AuditLogEntry;
use App\Models\Workspace;
use App\Services\Billing\PlanCaps;
use App\Services\StripePriceCache;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Workspace billing page. Always accessible (the trial-expiry guard whitelists
 * this route) so an expired-trial workspace can subscribe and unstick itself.
 *
 * Stripe Checkout is created on the fly via Cashier — we never store the
 * session URL anywhere. Stripe Product/Price IDs are lazy-created on first
 * checkout per plan via App\Services\StripePriceCache and cached in the
 * stripe_prices table. No STRIPE_PRICE_* env vars exist anymore — all plan
 * data lives in config/billing.php.
 */
class Billing extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Billing';
    protected static ?string $title = 'Billing';
    protected static ?int $navigationSort = 12;
    protected string $view = 'filament.agency.pages.billing';

    public function getSubheading(): ?string
    {
        return 'Your plan, trial countdown, and Stripe subscription. Upgrade or change plan here.';
    }

    public ?Workspace $workspace = null;
    public ?string $trialBadge = null;
    public ?string $statusLabel = null;
    public ?string $planLabel = null;
    public bool $hasActiveSub = false;

    /**
     * Cancellation lifecycle state for the view's grace banners + button gating.
     * One of: active | grace_period | read_only_grace | expired.
     * @see \App\Models\Workspace::cancellationState()
     */
    public ?string $cancellationState = null;

    /** Formatted date the cancel-at-period-end grace ends (paid access until then). */
    public ?string $gracePeriodEndsAt = null;

    /** Formatted date the 30-day post-cancellation read-only grace ends. */
    public ?string $readOnlyGraceEndsAt = null;

    /** Per-month usage snapshot driven by PlanCaps. Surfaced on the page. */
    public ?array $usage = null;

    /**
     * Pricing display block — monthly MYR, annual MYR (= monthly × annual
     * multiplier), savings vs 12-monthly. Driven by config/billing.php so
     * the UI never lies about what Stripe will actually charge.
     *
     * @var array{
     *   monthly_myr: int,
     *   annual_myr: int,
     *   annual_savings_myr: int,
     *   tax_note: string,
     * }|null
     */
    public ?array $pricing = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->workspace = $user?->currentWorkspace
            ?? $user?->workspaces()->first()
            ?? $user?->ownedWorkspaces()->first();

        if (! $this->workspace) {
            return;
        }

        // Plan label reads MYR price directly from config/billing.php so the
        // page stays in sync with the source of truth (no risk of stale
        // labels if pricing changes again).
        $planConfig = config('billing.plans.' . $this->workspace->plan);
        $priceMyr = (int) ($planConfig['price_myr'] ?? 0);
        $planName = (string) ($planConfig['name'] ?? ucfirst((string) $this->workspace->plan));
        $this->planLabel = $this->workspace->plan === 'eiaaw_internal'
            ? 'EIAAW internal'
            : "{$planName} · RM " . number_format($priceMyr) . ' / mo';

        // Usage snapshot: brands used / limit, posts published this month /
        // limit, AI videos this month / limit. Customer-facing — drives the
        // "you've used 80%, upgrade for more" prompts.
        $caps = app(PlanCaps::class)->capsFor($this->workspace);
        $this->usage = [
            'brands_used' => $this->workspace->activeBrandsCount(),
            'brands_cap' => $caps['max_brands'],
            'posts_used' => $this->workspace->publishedPostsThisMonth(),
            'posts_cap' => $caps['max_published_posts_per_month'],
            'videos_used' => $this->workspace->aiVideosThisMonth(),
            'videos_cap' => $caps['max_ai_videos_per_month'],
        ];

        // Pricing block — shown for non-EIAAW-internal workspaces.
        if ($planConfig && $this->workspace->plan !== 'eiaaw_internal') {
            $this->pricing = [
                'monthly_myr' => (int) ($planConfig['price_myr'] ?? 0),
                'annual_myr' => StripePriceCache::annualMyr($planConfig),
                'annual_savings_myr' => StripePriceCache::annualSavingsMyr($planConfig),
                'tax_note' => $this->buildTaxNote(),
            ];
        }

        // Status + trial badge + active flag. These were previously stranded as
        // dead code AFTER the early `return` inside buildTaxNote(), so they never
        // ran — $hasActiveSub was always false (hiding the Manage/Cancel buttons)
        // and $statusLabel/$trialBadge were always null. They belong here in
        // mount() where $this->workspace is populated.
        $this->statusLabel = match ($this->workspace->subscription_status) {
            'trialing' => 'Free trial',
            'active' => 'Active subscription',
            'past_due' => 'Payment failed — please update card',
            'canceled' => $this->cancellationStatusLabel(),
            'none' => 'Trial ended — subscribe to continue',
            default => ucfirst((string) $this->workspace->subscription_status),
        };

        if ($this->workspace->subscription_status === 'trialing'
            && $this->workspace->trial_ends_at
        ) {
            // Carbon 3 (Laravel 11) returns a float from diffInDays; ceil so the
            // user sees "Trial ends in 14 days", not "13.98… days".
            $days = (int) ceil(now()->diffInDays($this->workspace->trial_ends_at, false));
            $this->trialBadge = $days > 0
                ? "Trial ends in {$days} day" . ($days === 1 ? '' : 's')
                : 'Trial ending today';
        }

        $this->hasActiveSub = method_exists($this->workspace, 'subscribed')
            && $this->workspace->subscribed('default');

        // Cancellation lifecycle, surfaced to the view for the grace banners
        // and the Cancel/Reactivate button visibility.
        $this->cancellationState = $this->workspace->cancellationState();
        $sub = $this->workspace->subscription('default');
        $this->gracePeriodEndsAt = $sub?->ends_at?->format('j M Y');
        $this->readOnlyGraceEndsAt = $this->workspace->readOnlyGraceEndsAt()?->format('j M Y');
    }

    /**
     * Human label for a canceled workspace, distinguishing the cancel-at-period-end
     * grace window (still has access) from a fully-ended subscription.
     */
    private function cancellationStatusLabel(): string
    {
        return match ($this->workspace?->cancellationState()) {
            'grace_period' => 'Cancelling — access continues until period end',
            'read_only_grace' => 'Subscription ended — data preserved during grace period',
            default => 'Subscription canceled',
        };
    }

    /**
     * Tax line shown under the pricing block. Three modes:
     *   - SST not enabled (current state) → "Prices exclusive of any taxes
     *     that may apply."
     *   - SST enabled, customer in MY → "+ 8% SST on top per RMCD. SST reg:
     *     {number}."
     *   - SST enabled, customer outside MY → "no SST applies."
     * Customer country is derived from Cashier when we have it; falls back
     * to MY (allowed_countries gate elsewhere ensures non-MY shouldn't reach
     * this page in v1).
     */
    private function buildTaxNote(): string
    {
        $taxEnabled = (bool) config('billing.tax.enabled', false);
        if (! $taxEnabled) {
            return 'Prices shown are exclusive of any applicable taxes. Stripe collects card payment in MYR; your card issuer may charge a foreign-transaction fee if billed outside Malaysia.';
        }
        $ratePct = round(((float) config('billing.tax.rate', 0.08)) * 100);
        $regNo = (string) config('billing.tax.registration_number', '');
        return sprintf(
            'Malaysian customers: + %d%% SST on top, charged automatically per RMCD. SST registration: %s.',
            $ratePct,
            $regNo !== '' ? $regNo : '(pending)',
        );
    }

    public function subscribeAction(): Action
    {
        return Action::make('subscribe')
            ->label('Subscribe with card')
            ->color('primary')
            ->icon('heroicon-o-credit-card')
            ->action(fn () => $this->openCheckout('monthly'));
    }

    public function subscribeAnnualAction(): Action
    {
        return Action::make('subscribeAnnual')
            ->label('Subscribe annually (2 months free)')
            ->color('gray')
            ->outlined()
            ->icon('heroicon-o-calendar')
            ->action(fn () => $this->openCheckout('annual'));
    }

    public function manageAction(): Action
    {
        return Action::make('manage')
            ->label('Manage subscription & invoices')
            ->color('gray')
            ->outlined()
            ->icon('heroicon-o-cog-6-tooth')
            ->visible(fn () => $this->hasActiveSub)
            ->action(fn () => $this->openCustomerPortal());
    }

    /**
     * Cancel-at-period-end. The customer keeps full access until the paid
     * period ends (Cashier grace period via Subscription::cancel(), which sets
     * ends_at) — the international SaaS norm and Malaysia CPA-fair: no
     * mid-cycle cutoff, no clawback. Confirmation modal explains the date,
     * offers ONE retention alternative (pause publishing instead), and
     * captures an OPTIONAL churn reason. Hidden once already cancelling.
     */
    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel subscription')
            ->color('danger')
            ->outlined()
            ->icon('heroicon-o-x-circle')
            ->visible(fn () => $this->hasActiveSub && $this->cancellationState === 'active')
            ->modalHeading('Cancel your subscription')
            ->modalDescription(
                // No live Stripe round-trip on modal open: Stripe will set the
                // exact end date when we call cancel(), and the confirmation
                // notification + email state it. Keep the modal copy generic.
                'You will keep full access until the end of the period you have already paid for — no further charges after that, and you can reactivate any time before then. '
                . 'Prefer to pause instead? You can stop all publishing for now and keep your plan from the dashboard, then resume when you are ready.',
            )
            ->modalSubmitActionLabel('Cancel at period end')
            ->modalIcon('heroicon-o-x-circle')
            ->schema([
                Select::make('reason')
                    ->label('What made you decide to cancel? (optional)')
                    ->options([
                        'too_expensive' => 'Too expensive',
                        'not_using' => 'Not using it enough',
                        'missing_feature' => 'Missing a feature I need',
                        'switching' => 'Switching to another tool',
                        'results' => "Didn't see the results I wanted",
                        'other' => 'Other',
                    ])
                    ->native(false),
                Textarea::make('reason_detail')
                    ->label('Anything we could have done better? (optional)')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->cancelSubscription($data));
    }

    /**
     * Reactivate a subscription that is cancel-at-period-end but whose paid
     * period has not yet ended. Cashier resume() clears ends_at; billing
     * resumes on the existing schedule. Only visible during the grace window.
     */
    public function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Reactivate subscription')
            ->color('primary')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn () => $this->cancellationState === 'grace_period')
            ->requiresConfirmation()
            ->modalHeading('Reactivate your subscription')
            ->modalDescription('Your plan will continue without interruption and billing resumes on your normal renewal date.')
            ->modalSubmitActionLabel('Reactivate')
            ->action(fn () => $this->resumeSubscription());
    }

    private function cancelSubscription(array $data): void
    {
        if (! $this->workspace) {
            $this->failNotification('No workspace found for your account.');
            return;
        }

        $subscription = $this->workspace->subscription('default');
        if (! $subscription || ! $subscription->active()) {
            $this->failNotification('No active subscription to cancel.');
            return;
        }

        try {
            // cancel() = cancel_at_period_end on Stripe; Cashier sets ends_at.
            $subscription->cancel();
        } catch (\Throwable $e) {
            Log::error('Subscription cancel failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
            $this->failNotification('Could not cancel right now: ' . $e->getMessage());
            return;
        }

        $endsAt = $this->workspace->subscription('default')?->ends_at;

        // Immutable audit row — actor, action, and the churn reason as context.
        // Never invent a column; reason + detail live in the JSON `context`.
        AuditLogEntry::create([
            'workspace_id' => $this->workspace->id,
            'actor_user_id' => auth()->id(),
            'actor_type' => 'user',
            'action' => 'subscription.cancel_requested',
            'subject_type' => Workspace::class,
            'subject_id' => $this->workspace->id,
            'context' => [
                'reason' => $data['reason'] ?? null,
                'reason_detail' => $data['reason_detail'] ?? null,
                'ends_at' => $endsAt?->toIso8601String(),
                'plan' => $this->workspace->plan,
            ],
            'occurred_at' => now(),
        ]);

        // Confirmation email — queued on the pinned Resend transport
        // ([[resend_mail_wiring]]). Non-fatal: a mail failure must not block
        // the cancellation the customer just confirmed.
        if ($this->workspace->owner) {
            try {
                Mail::to($this->workspace->owner->email)
                    ->queue(new SubscriptionCancelled($this->workspace, $endsAt));
            } catch (\Throwable $e) {
                Log::error('SubscriptionCancelled email queue failed', [
                    'workspace_id' => $this->workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Notification::make()
            ->title('Subscription cancelled')
            ->body($endsAt
                ? 'You keep full access until ' . $endsAt->format('j M Y') . '. Reactivate any time before then.'
                : 'Your subscription will end at the close of the current billing period.')
            ->success()
            ->send();

        // Refresh the page-level state so the banner + buttons flip immediately.
        $this->mount();
    }

    private function resumeSubscription(): void
    {
        if (! $this->workspace) {
            $this->failNotification('No workspace found for your account.');
            return;
        }

        $subscription = $this->workspace->subscription('default');
        if (! $subscription || ! $subscription->onGracePeriod()) {
            $this->failNotification('This subscription is not in a state that can be reactivated.');
            return;
        }

        try {
            $subscription->resume();
        } catch (\Throwable $e) {
            Log::error('Subscription resume failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
            $this->failNotification('Could not reactivate right now: ' . $e->getMessage());
            return;
        }

        AuditLogEntry::create([
            'workspace_id' => $this->workspace->id,
            'actor_user_id' => auth()->id(),
            'actor_type' => 'user',
            'action' => 'subscription.resumed',
            'subject_type' => Workspace::class,
            'subject_id' => $this->workspace->id,
            'context' => ['plan' => $this->workspace->plan],
            'occurred_at' => now(),
        ]);

        Notification::make()
            ->title('Subscription reactivated')
            ->body('Your plan continues without interruption.')
            ->success()
            ->send();

        $this->mount();
    }

    /**
     * Returns a redirect (or null on error after a notification).
     * No strict return type: when called inside a Filament/Livewire action,
     * redirect(...) returns Livewire\Features\SupportRedirects\Redirector,
     * not Illuminate\Http\RedirectResponse — a strict type triggers a
     * "Return value must be of type ?RedirectResponse, Redirector returned"
     * fatal at the framework boundary.
     */
    private function openCheckout(string $period)
    {
        if (! $this->workspace) {
            $this->failNotification('No workspace found for your account.');
            return null;
        }

        if ($this->workspace->plan === 'eiaaw_internal') {
            $this->failNotification('EIAAW internal workspaces are not billed.');
            return null;
        }

        // 'monthly' / 'annual' from the action → Stripe interval
        $interval = $period === 'annual' ? 'year' : 'month';

        try {
            $priceId = app(StripePriceCache::class)->getOrCreate($this->workspace->plan, $interval);
        } catch (\Throwable $e) {
            Log::error('StripePriceCache::getOrCreate failed', [
                'workspace_id' => $this->workspace->id,
                'plan' => $this->workspace->plan,
                'interval' => $interval,
                'error' => $e->getMessage(),
            ]);
            $this->failNotification('Could not resolve price for this plan. Please contact support — error: '.$e->getMessage());
            return null;
        }

        try {
            $checkout = $this->workspace
                ->newSubscription('default', $priceId)
                ->checkout([
                    'success_url' => url('/agency/billing?status=success'),
                    'cancel_url' => url('/agency/billing?status=cancel'),
                ]);

            return redirect($checkout->url);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
            $this->failNotification('Could not start checkout: ' . $e->getMessage());
            return null;
        }
    }

    /** @see openCheckout() — same return-type rationale. */
    private function openCustomerPortal()
    {
        if (! $this->workspace) {
            return null;
        }

        try {
            $url = $this->workspace->billingPortalUrl(url('/agency/billing'));
            return redirect($url);
        } catch (\Throwable $e) {
            Log::error('Stripe portal failed', [
                'workspace_id' => $this->workspace->id,
                'error' => $e->getMessage(),
            ]);
            $this->failNotification('Could not open billing portal: ' . $e->getMessage());
            return null;
        }
    }

    private function failNotification(string $body): void
    {
        Notification::make()
            ->title('Subscription error')
            ->body($body)
            ->danger()
            ->send();
    }
}
