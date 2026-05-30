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

        // Content autopilot: the FRONT half of the autonomous loop. Hourly,
        // for every eligible brand (active access + NOT publishing-paused),
        // top the calendar back up (Strategist only when coverage is thin)
        // and dispatch idempotent DraftCalendarEntry jobs for undrafted
        // (entry, platform) pairs — bounded by the plan's remaining monthly
        // post allowance. Approval is decided downstream by each draft's lane
        // (green auto-approves; amber waits for a human), so this honours the
        // operator's approver selection without deciding it here. Runs every
        // day, non-stop, until the operator clicks "Stop publishing" on the
        // workspace (which sets publishing_paused and short-circuits this).
        // Hourly cadence (not every-minute) caps LLM/FAL spend — calendars are
        // monthly and drafting is incremental, so an hour of latency on "the
        // calendar ran thin" is invisible to the customer.
        $schedule->command('content:autopilot')
            ->hourly()
            ->withoutOverlapping(55)
            ->runInBackground();

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

        // Weekly competitor intel refresh — Mondays 03:00 UTC, just after
        // Optimizer (02:00). Pulls competitor ads from Meta Ad Library +
        // LinkedIn (via Firecrawl) into competitor_ads with a 30-day rolling
        // window. Strategist consumes both Optimizer recommendation and the
        // refreshed intel on its next calendar build.
        $schedule->command('intel:refresh')
            ->weekly()->mondays()->at('03:00')
            ->withoutOverlapping(60)
            ->runInBackground();

        // Plan-cap release valve: hourly, flip any scheduled_posts that were
        // deferred to next period back to status='queued' once their
        // queued_for_period_at has arrived. Hourly cadence is plenty — the
        // worst-case latency is "publishes within an hour of the new month
        // starting", which is well below user-visible expectations.
        $schedule->command('posts:release-queued-next-period')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();

        // Cap-warning sweep: daily at 09:00 UTC (mid-morning APAC), email
        // workspaces that have crossed 80% of their monthly post cap. Once
        // per period per workspace — see PostsWarnNearCap throttle key.
        $schedule->command('posts:warn-near-cap')
            ->dailyAt('09:00')
            ->withoutOverlapping(30)
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
        // Floating support chatbot endpoints: the PUBLIC landing widget posts
        // without a session/XSRF token (same as the corporate site's chatbot),
        // and the panel widgets reuse the same endpoints. Abuse is bounded by
        // tight per-IP rate limits (routes/web.php), input validation, and the
        // LlmGateway prompt-injection detector — not CSRF, which a logged-out
        // visitor can't carry. No state is mutated by /api/chatbot; /api/contact
        // only inserts a lead row + sends a notification.
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'csp-report',
            'api/chatbot',
            'api/contact',
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
