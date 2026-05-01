<?php

namespace App\Providers;

use App\Listeners\EnsureUserHasWorkspace;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Login::class, EnsureUserHasWorkspace::class);
    }
}
