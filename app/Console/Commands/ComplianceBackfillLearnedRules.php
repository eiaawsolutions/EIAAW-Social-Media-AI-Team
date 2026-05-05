<?php

namespace App\Console\Commands;

use App\Models\ComplianceCheck;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Services\Compliance\LearnedRulesRecorder;
use Illuminate\Console\Command;

/**
 * One-shot backfill: walk every historical scheduled_post in {failed,
 * cancelled} state and every draft in compliance_failed and feed them
 * through LearnedRulesRecorder. After this runs, compliance_learned_rules
 * has the same coverage it would have had if the recorder had been live
 * since day one.
 *
 * Idempotent — the recorder upserts on (workspace, platform, kind,
 * fingerprint) so re-running just bumps occurrence counters. Safe to run
 * multiple times.
 *
 * Usage:
 *   php artisan compliance:backfill-learned-rules           # all workspaces
 *   php artisan compliance:backfill-learned-rules --dry-run # show what would be recorded
 */
class ComplianceBackfillLearnedRules extends Command
{
    protected $signature = 'compliance:backfill-learned-rules {--dry-run}';
    protected $description = 'Replay historical rejections through LearnedRulesRecorder so the rules table starts populated.';

    public function handle(LearnedRulesRecorder $recorder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Backfilling compliance_learned_rules from historical rejections…');
        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $scheduledPostCount = 0;
        $publishabilityCount = 0;

        // 1) Replay scheduled_posts that ended in failed/cancelled with
        //    a parseable last_error. Pull through Eloquent so we get the
        //    same draft + brand relations the recorder uses live.
        ScheduledPost::with(['draft', 'brand'])
            ->whereIn('status', ['failed', 'cancelled'])
            ->whereNotNull('last_error')
            ->orderBy('id')
            ->chunkById(200, function ($posts) use ($recorder, $dryRun, &$scheduledPostCount) {
                foreach ($posts as $post) {
                    if (! $post->draft || ! $post->draft->platform) continue;

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  [scheduled_post] id=%d plat=%s reason=%s',
                            $post->id,
                            $post->draft->platform,
                            mb_substr((string) $post->last_error, 0, 120),
                        ));
                    } else {
                        $recorder->recordRejection($post, (string) $post->last_error, null);
                    }
                    $scheduledPostCount++;
                }
            });

        // 2) Replay every failed platform_publishability check. These hold
        //    the structured violation array in details.violations, which
        //    is the cleanest signal for the recorder.
        ComplianceCheck::query()
            ->where('check_type', 'platform_publishability')
            ->where('result', 'fail')
            ->orderBy('id')
            ->chunkById(200, function ($checks) use ($recorder, $dryRun, &$publishabilityCount) {
                foreach ($checks as $check) {
                    /** @var Draft|null $draft */
                    $draft = Draft::with('brand')->find($check->draft_id);
                    if (! $draft) continue;

                    $details = is_array($check->details) ? $check->details : [];
                    $violations = $details['violations'] ?? [];

                    foreach ($violations as $v) {
                        if ($dryRun) {
                            $this->line(sprintf(
                                '  [publishability] draft=%d plat=%s kind=%s',
                                $draft->id,
                                $draft->platform,
                                $v['kind'] ?? 'unknown',
                            ));
                        } else {
                            $recorder->recordPublishabilityViolation($draft, $v);
                        }
                        $publishabilityCount++;
                    }
                }
            });

        $this->info(sprintf(
            'Replayed %d scheduled_post rejection(s) and %d publishability violation(s).',
            $scheduledPostCount,
            $publishabilityCount,
        ));
        if (! $dryRun) {
            $this->info('compliance_learned_rules now reflects the full historical signal.');
        }

        return self::SUCCESS;
    }
}
