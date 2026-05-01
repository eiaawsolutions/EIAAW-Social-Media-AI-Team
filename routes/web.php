<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('landing'))->name('landing');

Route::redirect('/login', '/agency/login')->name('login');

Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'env' => config('app.env'),
        'time' => now()->toIso8601String(),
        'php' => PHP_VERSION,
    ]);
})->name('health');
