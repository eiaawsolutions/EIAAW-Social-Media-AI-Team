<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot recovery command: cancels a stuck/private ScheduledPost and
 * resets its linked draft so the auto-scheduler creates a fresh SP that
 * will re-submit with the *current* defaults in BlotatoClient.
 *
 * Use case: TikTok rows stuck in `submitted` because Blotato's TikTok
 * adapter returned only the profile-root URL (no /video/<id>). Likely
 * cause: prior code submitted them with privacyLevel=SELF_ONLY so the
 * post landed as a draft on the platform side, never made it to the
 * public feed, and Blotato's adapter never got a real permalink to
 * report back. After flipping the default to PUBLIC_TO_EVERYONE
 * (commit on 2026-05-07), re-running this command on those SP ids
 * resubmits them as public.
 *
 * What it does NOT do:
 *   - Try to delete the existing TikTok draft on the platform — TikTok
 *     drafts are user-deletable inside the TikTok app, not via Blotato.
 *     If you don't clean those up manually you'll have a duplicate.
 *   - Republish a row that's truly published on the public platform
 *     (with a real /video/<id> or /watch?v=<id> permalink). That would
 *     create a duplicate post, which is destructive. Operator must pass
 *     --i-know-this-publishes-twice to override.
 *
 * Required input: SP ids (comma-separated).
 *
 * Default is dry-run. Pass --apply to actually mutate state.
 */
class PostsRepublishStuck extends Command
{
    protected $signature = 'posts:republish-stuck
                            {ids : comma-separated ScheduledPost ids to republish}
                            {--apply : actually write changes (default is dry-run)}
                            {--i-know-this-publishes-twice : allow re-publishing rows that already have a verified public URL (will create a duplicate on the platform)}';

    protected $description = 'Cancel a stuck/private ScheduledPost + reset draft so auto-scheduler creates a fresh SP under current BlotatoClient defaults.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $allowDup = (bool) $this->option('i-know-this-publishes-twice');

        $ids = collect(explode(',', (string) $this->argument('ids')))
            ->map(fn ($x) => (int) trim($x))
            ->filter()
            ->values()
            ->all();

        if (empty($ids)) {
            $this->error('No valid ids provided.');
            return self::FAILURE;
        }

        $rows = ScheduledPost::with('draft')->whereIn('id', $ids)->get();
        if ($rows->isEmpty()) {
            $this->error('No matching ScheduledPosts found.');
            return self::FAILURE;
        }

        $stats = ['eligible' => 0, 'skipped_published' => 0, 'skipped_state' => 0,
                  'cancelled' => 0, 'draft_reset' => 0];

        foreach ($rows as $sp) {
            $this->line('');
            $this->info(sprintf('SP%d (%s) status=%s', $sp->id, $sp->draft?->platform ?? '?', $sp->status));

            // Guard 1: already-verified-public rows. Republishing creates a duplicate.
            $hasRealUrl = $sp->platform_post_url
                && \App\Services\Publishing\PostVerificationRules::isRealPostUrl(
                    (string) $sp->draft?->platform,
                    $sp->platform_post_url
                );
            if ($hasRealUrl && ! $allowDup) {
                $this->warn(sprintf(
                    '  → SKIP: row has a verified public URL (%s). Republishing would duplicate the post on the platform. Pass --i-know-this-publishes-twice to override.',
                    $sp->platform_post_url,
                ));
                $stats['skipped_published']++;
                continue;
            }

            // Guard 2: ineligible state. We only act on submitted (stuck-private)
            // and failed (genuine reject) rows. queued is already going to
            // resubmit on its own; published-without-verified-url falls through.
            if (! in_array($sp->status, ['submitted', 'failed', 'published'], true)) {
                $this->warn('  → SKIP: status='.$sp->status.' is not eligible for republish.');
                $stats['skipped_state']++;
                continue;
            }

            $stats['eligible']++;

            if (! $sp->draft) {
                $this->warn('  → SKIP: linked draft missing.');
                $stats['skipped_state']++;
                continue;
            }

            // What we'll do:
            //   1. Cancel this SP (status=cancelled).
            //   2. Reset draft.status to 'approved' so PostsAutoScheduleApproved
            //      picks it up next minute and creates a fresh SP.
            //   3. Wipe draft.scheduled status if it was already there.
            $this->line('  Plan: cancel SP'.$sp->id.', reset draft#'.$sp->draft->id.' → approved.');

            if (! $apply) {
                $this->comment('  [dry-run] no changes written.');
                continue;
            }

            DB::transaction(function () use ($sp, &$stats) {
                $sp->update([
                    'status' => 'cancelled',
                    'last_error' => 'Cancelled by posts:republish-stuck on '.now()->toIso8601String()
                        .' to resubmit under updated BlotatoClient defaults (TikTok PUBLIC_TO_EVERYONE / YouTube public).',
                ]);
                $stats['cancelled']++;

                $sp->draft->update(['status' => 'approved']);
                $stats['draft_reset']++;
            });

            $this->info('  → CANCELLED SP, draft reset to approved.');
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line('eligible:                '.$stats['eligible']);
        $this->line('skipped (already public): '.$stats['skipped_published']);
        $this->line('skipped (other state):   '.$stats['skipped_state']);
        $this->line('cancelled:               '.$stats['cancelled']);
        $this->line('drafts reset:            '.$stats['draft_reset']);
        $this->line('');
        if (! $apply) {
            $this->warn('DRY-RUN — nothing written. Re-run with --apply to commit.');
        } else {
            $this->info('Drafts will be picked up by posts:auto-schedule-approved on the next cron tick (≤1 min).');
        }
        return self::SUCCESS;
    }
}
