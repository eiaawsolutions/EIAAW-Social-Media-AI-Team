<?php

namespace App\Providers\Filament;

use App\Filament\Agency\Pages\SetupWizard;
use App\Filament\Agency\Widgets\ReadinessHeader;
use App\Http\Middleware\RedirectToSetupIfIncomplete;
use App\Filament\Agency\Auth\Register;
use App\Filament\Agency\Pages\Billing;
use App\Filament\Agency\Pages\TrialExpired;
use App\Http\Middleware\EnforceTrialOrSubscription;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AgencyPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('agency')
            ->path('agency')
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->brandName('EIAAW Social Media Team')
            ->brandLogo(asset('brand/shield.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('brand/shield.png'))
            ->colors([
                // Map Filament's primary to EIAAW deep teal #11766A
                'primary' => Color::hex('#11766A'),
                'gray' => Color::Stone,
            ])
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/Agency/Resources'), for: 'App\\Filament\\Agency\\Resources')
            ->discoverPages(in: app_path('Filament/Agency/Pages'), for: 'App\\Filament\\Agency\\Pages')
            ->pages([
                SetupWizard::class,
                Billing::class,
                TrialExpired::class,
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Agency/Widgets'), for: 'App\\Filament\\Agency\\Widgets')
            ->widgets([
                ReadinessHeader::class,
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
                RedirectToSetupIfIncomplete::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceTrialOrSubscription::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render('<link rel="stylesheet" href="' . asset('brand/auth.css') . '?v=' . filemtime(public_path('brand/auth.css')) . '">'),
                scopes: [Login::class, Register::class, RequestPasswordReset::class, ResetPassword::class],
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn (): string => view('filament.agency.auth.hero')->render(),
                scopes: [Login::class, Register::class, RequestPasswordReset::class, ResetPassword::class],
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => view('filament.agency.auth.return-link')->render(),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE,
                fn (): string => view('filament.agency.auth.return-link')->render(),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE,
                fn (): string => view('filament.agency.auth.return-link')->render(),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_PASSWORD_RESET_RESET_FORM_BEFORE,
                fn (): string => view('filament.agency.auth.return-link')->render(),
            );
    }
}
