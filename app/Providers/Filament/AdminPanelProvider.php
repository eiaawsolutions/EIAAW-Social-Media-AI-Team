<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Full EIAAW branding — mirrors the customer-facing agency panel so
            // the HQ admin surface is unmistakably an EIAAW product, not the
            // default amber Filament scaffold.
            ->brandName('EIAAW Solutions — HQ')
            ->brandLogo(asset('brand/shield.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand/shield.png'))
            ->colors([
                // EIAAW deep teal #11766A (see eiaaw-design-system.md, LOCKED).
                'primary' => Color::hex('#11766A'),
                'gray' => Color::Stone,
            ])
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Return the operator to their own HQ account (the agency panel).
            // Sorted to the top of the sidebar so the exit is always reachable.
            ->navigationItems([
                NavigationItem::make('return-to-hq')
                    ->label('Return to HQ account')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->url(fn (): string => \Filament\Facades\Filament::getPanel('agency')->getUrl())
                    ->sort(-100),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Floating support chatbot — HQ surface (guide-steps + enquiry).
            // Injected at body-end so it floats over every HQ page. The server
            // re-derives the surface and won't serve the guide prompt to an
            // unauthenticated caller; here the operator is always authenticated.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render("@include('partials.smt-chat-widget', ['surface' => 'hq'])"),
            );
    }
}
