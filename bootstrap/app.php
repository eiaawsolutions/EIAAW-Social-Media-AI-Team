<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Stripe webhook: signature is verified by Cashier's middleware
        // instead of CSRF (Stripe doesn't see our session).
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Don't strand a signup-funnel user on Laravel's default 419 page.
        // Laravel maps TokenMismatchException -> HttpException(419) BEFORE
        // render callbacks fire (see Foundation\Exceptions\Handler line ~641),
        // so we match on the wrapped HttpException with statusCode 419.
        // For the public signup paths, redirect back to /signup with a flash
        // message and let them re-pick a plan rather than dead-ending on the
        // generic "PAGE EXPIRED" page.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            if ($request->is('billing/checkout/*') || $request->is('signup*')) {
                return redirect('/signup')
                    ->with('error', 'Your session timed out. Please pick your plan again — your details haven\'t been submitted yet.');
            }
            return null;
        });
    })->create();
