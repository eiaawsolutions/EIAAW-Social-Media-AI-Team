<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resend API Key (resend/resend-laravel package)
    |--------------------------------------------------------------------------
    |
    | The resend-laravel package's ResendServiceProvider reads its API key from
    | config('resend.api_key') — a DIFFERENT path than the Laravel mail
    | transport's services.resend.key. Both must be populated for queued
    | Resend mail to actually send (the transport builds the message; the
    | package's client transmits it).
    |
    | We point BOTH at the same source: env('RESEND_KEY') holds a `secret://`
    | handle resolved at boot by SecretsServiceProvider. config/secrets.php
    | allow-lists both `services.resend.key` AND `resend.api_key` so the handle
    | resolves identically into each — one Infisical secret, one Railway env,
    | no second handle to keep in sync.
    |
    | Why this file exists at all: without a published config/resend.php the
    | package falls back to env('RESEND_API_KEY'), which we deliberately do NOT
    | set (the EIAAW convention is one handle in RESEND_KEY). A queued mail then
    | fails in the worker with ApiKeyIsMissing while the command that enqueued
    | it reports success — the exact silent-failure this file prevents.
    */

    'api_key' => env('RESEND_KEY'),

];
