<?php

return [
    // MUST be first — rewrites `secret://...` config values from Infisical
    // before other providers (Mail, Stripe, queue, R2) read their config.
    App\Providers\SecretsServiceProvider::class,

    App\Providers\AppServiceProvider::class,
    // Gate /horizon (queue dashboard) on is_super_admin.
    App\Providers\HorizonServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\AgencyPanelProvider::class,
];
