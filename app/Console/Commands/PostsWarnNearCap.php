<?php

namespace App\Console\Commands;

use App\Mail\PostsCapWarning;
use App\Models\Workspace;
use App\Services\Billing\PlanCaps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily sweep — find workspaces whose published-posts usage has crossed
 * 80% of their plan cap this month, and email the owner (once per month
 * per workspace) so they have time to upgrade before posts start queuing.
 *
 * Throttle: cache key `posts_cap_warning_sent:{ws_id}:{YYYY-MM}`, TTL =
 * end of current month + 1 day. Cache (Redis) is the right store — DB
 * would mean a new table for one boolean per workspace per month.
 *
 * Skips: eiaaw_internal plan, workspaces with paused publishing (they
 * already know), workspaces with no owner email (defensive).
 *
 * Why daily not hourly: 80% is a soft signal, not a real-time event.
 * Hourly would burn Resend quota on every workspace tick. Daily catches
 * the crossing within 24h, which is plenty for an upgrade decision.
 *
 * Usage:
 *   php artisan posts:warn-near-cap            # send
 *   php artisan posts:warn-near-cap --dry-run  # report, don't send/throttle
 */
class PostsWarnNearCap extends Command
{
    protected $signature = 'posts:warn-near-cap
                            {--dry-run : List candidates without sending or recording throttle}
                            {--workspace= : Limit to one workspace ID (debugging)}';

    protected $description = 'Daily: email workspace owners whose post usage hit 80% of cap this month (once per period).';

    public function handle(PlanCaps $caps): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('workspace') ? (int) $this->option('workspace') : null;
        $mailer = (string) config('mail.cap_warning.mailer', 'resend');

        $q = Workspace::query()
            ->where('plan', '!=', 'eiaaw_internal')
            ->whereNull('suspended_at')
            ->where(function ($q) {
                $q->whereNull('publishing_paused')->orWhere('publishing_paused', false);
            });

        if ($only) $q->where('id', $only);

        $workspaces = $q->with('owner:id,name,email')->get();

        $stats = ['checked' => 0, 'near_cap' => 0, 'sent' => 0, 'already_warned' => 0, 'skipped_no_owner' => 0];
        $period = now()->format('Y-m');

        foreach ($workspaces as $ws) {
            $stats['checked']++;

            if (! $caps->isNearPostCap($ws)) {
                continue;
            }
            $stats['near_cap']++;

            $owner = $ws->owner;
            if (! $owner?->email) {
                $stats['skipped_no_owner']++;
                $this->warn(sprintf('WS#%d (%s) skipped — owner has no email', $ws->id, $ws->slug));
                continue;
            }

            $throttleKey = "posts_cap_warning_sent:{$ws->id}:{$period}";

            if (Cache::has($throttleKey)) {
                $stats['already_warned']++;
                $this->line(sprintf('WS#%d already warned this period', $ws->id));
                continue;
            }

            $capsArr = $caps->capsFor($ws);
            $used = $ws->publishedPostsThisMonth();
            $cap = $capsArr['max_published_posts_per_month'];
            $pct = $cap > 0 ? (int) min(100, round($used / $cap * 100)) : 0;

            $this->info(sprintf(
                'WS#%d (%s) → %d/%d posts (%d%%) → emailing %s',
                $ws->id, $ws->slug, $used, $cap, $pct, $owner->email,
            ));

            if ($dryRun) continue;

            try {
                Mail::mailer($mailer)
                    ->to($owner->email, $owner->name ?? null)
                    ->queue(new PostsCapWarning($ws, $used, $cap, $pct));

                // TTL: end of current month at workspace TZ + 1 hour buffer.
                // Once month rolls over, a new period key is used and we
                // can warn again if they cross 80% again.
                $tz = (string) ($ws->settings['timezone'] ?? config('app.timezone', 'UTC'));
                $ttlSeconds = now()->diffInSeconds(now($tz)->endOfMonth()->utc()->addHour(), false);
                Cache::put($throttleKey, true, max(3600, (int) $ttlSeconds));

                $stats['sent']++;
            } catch (\Throwable $e) {
                Log::error('PostsWarnNearCap: mail send failed', [
                    'workspace_id' => $ws->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn(sprintf('  WS#%d send failed: %s', $ws->id, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info("Summary: checked={$stats['checked']}, near_cap={$stats['near_cap']}, sent={$stats['sent']}, already_warned={$stats['already_warned']}, no_owner={$stats['skipped_no_owner']}");
        if ($dryRun) $this->warn('DRY-RUN — no mails sent, no throttle keys set.');

        return self::SUCCESS;
    }
}
