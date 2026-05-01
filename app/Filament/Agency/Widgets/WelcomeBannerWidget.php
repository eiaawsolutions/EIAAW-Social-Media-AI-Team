<?php

namespace App\Filament\Agency\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Http\Request;

/**
 * Renders only when the user has just been provisioned via the Stripe
 * Checkout success URL (`?welcome=1`). Once mounted, it fires an AJAX
 * GET to /billing/welcome-token to retrieve the one-time temp password
 * generated during account creation, and shows it inside a
 * "save your credentials" card.
 *
 * Sort -200 so it sits above the readiness header.
 */
class WelcomeBannerWidget extends Widget
{
    protected string $view = 'filament.agency.widgets.welcome-banner';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -200;

    public static function canView(): bool
    {
        // Visible when the URL contains ?welcome=1 — the cookie + token
        // exchange handles the actual security. Filament re-renders on
        // every page load, so once the user navigates away the banner
        // stops appearing automatically.
        return request()->boolean('welcome');
    }

    public function getViewData(): array
    {
        return [
            'welcomeTokenUrl' => route('billing.welcome-token'),
            'email'           => auth()->user()?->email,
        ];
    }
}
