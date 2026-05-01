<?php

namespace App\Filament\Agency\Pages;

use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Workspace billing page. Always accessible (the trial-expiry guard whitelists
 * this route) so an expired-trial workspace can subscribe and unstick itself.
 *
 * Stripe Checkout is created on the fly via Cashier — we never store the
 * session URL anywhere. Price IDs come from env (populated via Infisical
 * per EIAAW Deploy Contract):
 *
 *   STRIPE_PRICE_SOLO_MYR_MONTHLY     STRIPE_PRICE_SOLO_MYR_ANNUAL
 *   STRIPE_PRICE_STUDIO_MYR_MONTHLY   STRIPE_PRICE_STUDIO_MYR_ANNUAL
 *   STRIPE_PRICE_AGENCY_MYR_MONTHLY   STRIPE_PRICE_AGENCY_MYR_ANNUAL
 *
 * If a price ID is missing the action will surface a clear error to the
 * user (rather than letting Cashier throw an unhelpful API error).
 */
class Billing extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Billing';
    protected static ?string $title = 'Billing';
    protected static ?int $navigationSort = 90;
    protected string $view = 'filament.agency.pages.billing';

    public ?Workspace $workspace = null;
    public ?string $trialBadge = null;
    public ?string $statusLabel = null;
    public ?string $planLabel = null;
    public bool $hasActiveSub = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->workspace = $user?->currentWorkspace
            ?? $user?->workspaces()->first()
            ?? $user?->ownedWorkspaces()->first();

        if (! $this->workspace) {
            return;
        }

        $this->planLabel = match ($this->workspace->plan) {
            'solo' => 'Solo · RM 99 / mo',
            'studio' => 'Studio · RM 299 / mo',
            'agency' => 'Agency · RM 799 / mo',
            'eiaaw_internal' => 'EIAAW internal',
            default => 'Unknown',
        };

        $this->statusLabel = match ($this->workspace->subscription_status) {
            'trialing' => 'Free trial',
            'active' => 'Active subscription',
            'past_due' => 'Payment failed — please update card',
            'canceled' => 'Subscription canceled',
            'none' => 'Trial ended — subscribe to continue',
            default => ucfirst((string) $this->workspace->subscription_status),
        };

        if ($this->workspace->subscription_status === 'trialing'
            && $this->workspace->trial_ends_at
        ) {
            $days = now()->diffInDays($this->workspace->trial_ends_at, false);
            $this->trialBadge = $days > 0
                ? "Trial ends in {$days} day" . ($days === 1 ? '' : 's')
                : 'Trial ending today';
        }

        $this->hasActiveSub = method_exists($this->workspace, 'subscribed')
            && $this->workspace->subscribed('default');
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

    private function openCheckout(string $period): ?RedirectResponse
    {
        if (! $this->workspace) {
            $this->failNotification('No workspace found for your account.');
            return null;
        }

        $priceId = $this->resolvePriceId($this->workspace->plan, $period);
        if (! $priceId) {
            Log::warning('Stripe price id missing', [
                'workspace_id' => $this->workspace->id,
                'plan' => $this->workspace->plan,
                'period' => $period,
            ]);
            $this->failNotification(
                'This plan is not yet available for self-serve checkout. Please email eiaawsolutions@gmail.com and we\'ll set up billing for you within the day.'
            );
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

    private function openCustomerPortal(): ?RedirectResponse
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

    private function resolvePriceId(string $plan, string $period): ?string
    {
        $key = strtoupper("STRIPE_PRICE_{$plan}_MYR_{$period}");
        $value = config('services.stripe.prices.' . strtolower("{$plan}_myr_{$period}"))
            ?? env($key);

        return is_string($value) && $value !== '' ? $value : null;
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
