<?php

namespace App\Filament\Agency\Pages;

use App\Models\Workspace;
use Filament\Pages\Page;

/**
 * Sticky paywall shown when a workspace's trial has expired and there is no
 * active subscription. The EnforceTrialOrSubscription middleware redirects
 * here from any other panel route.
 *
 * Hidden from the navigation by default — only reachable via the redirect
 * (or a direct URL hit during testing).
 */
class TrialExpired extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';
    protected static ?string $title = 'Your trial has ended';
    protected string $view = 'filament.agency.pages.trial-expired';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?Workspace $workspace = null;
    public ?string $endedAtHuman = null;
    public ?string $planLabel = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->workspace = $user?->currentWorkspace
            ?? $user?->workspaces()->first()
            ?? $user?->ownedWorkspaces()->first();

        if ($this->workspace?->trial_ends_at) {
            $this->endedAtHuman = $this->workspace->trial_ends_at->isFuture()
                ? $this->workspace->trial_ends_at->diffForHumans()
                : $this->workspace->trial_ends_at->format('j M Y');
        }

        // Label derives from config/billing.php so it never drifts when prices
        // or tiers change (was hardcoded RM 99/299/799 — two pricing eras stale).
        // Enterprise has no catalog price (bespoke, is_contact) so it reads as a
        // "Talk to us" label rather than a RM figure.
        $plan = $this->workspace?->plan;
        $planConfig = $plan ? config("billing.plans.{$plan}") : null;

        if (! $planConfig) {
            $this->planLabel = 'Choose a plan';
        } elseif (! empty($planConfig['is_contact'])) {
            $this->planLabel = (string) ($planConfig['name'] ?? 'Enterprise') . ' · Talk to us';
        } else {
            $name = (string) ($planConfig['name'] ?? ucfirst((string) $plan));
            $price = (int) ($planConfig['price_myr'] ?? 0);
            $this->planLabel = "{$name} · RM " . number_format($price) . ' / mo';
        }
    }
}
