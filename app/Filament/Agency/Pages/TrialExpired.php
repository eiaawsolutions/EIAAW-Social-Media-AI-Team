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

        $this->planLabel = match ($this->workspace?->plan) {
            'solo' => 'Solo · RM 99 / mo',
            'studio' => 'Studio · RM 299 / mo',
            'agency' => 'Agency · RM 799 / mo',
            default => 'Choose a plan',
        };
    }
}
