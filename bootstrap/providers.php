<?php

return [
    // MUST be first — rewrites `secret://...` config values from Infisical
    // before other providers (Mail, Stripe, queue, R2) read their config.
    App\Providers\SecretsServiceProvider::class,

    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\AgencyPanelProvider::class,
];
