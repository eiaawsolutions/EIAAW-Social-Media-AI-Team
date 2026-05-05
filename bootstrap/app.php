<?php

use Illuminate\Console\Scheduling\Schedule;
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
    ->withSchedule(function (Schedule $schedule): void {
        // Mutex TTL of 5 minutes on every withoutOverlapping() call —
        // bounds the blast radius if a container is SIGKILLed mid-run.
        // Default TTL is 24h, which means an orphaned mutex stalls the
        // entire pipeline for a full day until manually cleared with
        // `php artisan schedule:clear-cache`. With 5-min TTL the scheduler
        // self-heals on the next minute after the lock expires.

        // Auto-schedule path: every minute, find approved drafts (green-lane
        // auto-approved or human-approved) that have no live ScheduledPost
        // yet, and queue them. Closes the loop between the autonomy lane
        // ("green = auto-publish") and the Schedule page actually filling.
        // Idempotent + race-safe (lockForUpdate inside a transaction).
        $schedule->command('posts:auto-schedule-approved')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();

        // Publish path: every minute, dispatch jobs for queued / pollable /
        // retryable scheduled posts. The Job itself is idempotent so even if
        // the cron fires twice in the same minute, we won't double-publish.
        // withoutOverlapping() guards against long-running dispatches piling
        // up if the queue is slow.
        $schedule->command('posts:dispatch-due')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();

        // Metrics collection — tiered sampling (every 30min for hot posts,
        // 6h for warm, 24h for cold). The command itself enforces tiering;
        // we just make sure it runs every 30 min so each tier gets a chance.
        $schedule->command('metrics:collect')
            ->everyThirtyMinutes()
            ->withoutOverlapping(30)
            ->runInBackground();

        // Optimizer: weekly recompute of the per-brand recommendation that
        // the Strategist consumes on its next calendar build. Runs Mondays
        // 02:00 UTC so by Monday morning the operator's brief reflects last
        // week's performance.
        $schedule->command('optimizer:run')
            ->weekly()->mondays()->at('02:00')
            ->withoutOverlapping(60)
            ->runInBackground();

        // Auto-redraft loop: every 5 minutes, find compliance_failed drafts
        // under the per-draft retry cap (3) and dispatch the Writer to fix
        // them with the failure reasons fed back. Compliance re-runs after.
        // 5-minute cadence (not every-minute) caps LLM cost on a backlog —
        // each redraft is ~$0.02-0.05. Cooldown enforces 10 min between
        // attempts on the same draft so a flaky check doesn't burn budget.
        $schedule->command('drafts:redraft-failed')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Apply defense-in-depth response headers (CSP / HSTS / X-Frame-Options
        // / Referrer-Policy / Permissions-Policy) to every response. Scanners
        // grade these as quick-wins; missing them shows up as "low" findings
        // on every pentest report.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Trusted Host validation defends against Host-header injection
        // (password-reset poisoning, cache poisoning). The pattern accepts
        // APP_URL's host plus any *.eiaawsolutions.com subdomain so the
        // SAINS / Workforce / Claritas siblings keep resolving when sharing
        // infra. Localhost / 127.0.0.1 always allowed for tooling.
        $middleware->trustHosts(at: function () {
            $hosts = ['localhost', '127.0.0.1', '^(.+\.)?eiaawsolutions\.com$'];
            $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if ($appUrlHost) {
                $hosts[] = preg_quote($appUrlHost, '/');
            }
            return $hosts;
        });

        // Stripe webhook: signature is verified by Cashier's middleware
        // instead of CSRF (Stripe doesn't see our session).
        // CSP report endpoint: the browser sends violation reports without
        // our XSRF token; the handler only writes log lines, never mutates
        // state, so the lack of CSRF is acceptable.
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'csp-report',
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
