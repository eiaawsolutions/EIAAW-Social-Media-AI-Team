<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovery command: requeue ScheduledPosts that FAILED for a *transient,
 * now-fixed provider reason* — flip them back to `queued`, reset
 * attempt_count to 0, and clear last_error so the regular dispatcher
 * (posts:dispatch-due) resubmits them under the corrected code path.
 *
 * Motivating incident (2026-06-01): every Metricool media-normalise call sent
 * `Accept: application/json`, but /actions/normalize/image/url responds
 * text/plain → Tomcat rejected with HTTP 406, so all media posts failed with
 * "Media normalize failed for <url> (HTTP 406)". After the MetricoolClient fix,
 * those rows are perfectly publishable — they just need requeuing. 14 of the 15
 * had already burned all 3 attempts (attempt_count >= 3), so the cron will
 * NEVER auto-retry them; this command resets the counter and revives them.
 *
 * SAFETY:
 *   - Match is by last_error substring (default: the 406 normalize pattern).
 *     Scope it to exactly the fixed failure class so we never revive a row that
 *     failed for a real, unfixed reason (bad media, revoked connection, etc.).
 *   - By default we REFUSE to touch any row that already holds a real provider
 *     submission id (blotato_post_id, not 'pending') — requeuing those could
 *     double-post. Those should be polled, not resubmitted.
 *   - EXCEPTION (--allow-provider-id): some failures happen AFTER the provider
 *     accepted the post — Metricool returns an id, then the platform delivery
 *     ERRORs (e.g. the 2026-06-20 "Metricool delivery ERROR" media-fetch 404s).
 *     Nothing is live, so a clean resubmit is safe. With this flag we permit
 *     provider-id rows AND clear blotato_post_id to null in the same
 *     transaction, so the dispatcher does a fresh `submit` (not a `poll`).
 *     USE ONLY for rows CONFIRMED ERROR/FAILED on-provider — verify against the
 *     Metricool scheduler list before applying, or you risk a duplicate.
 *   - Dry-run by default. Pass --apply to write.
 *   - Operates across ALL workspaces by default (the bug was account-wide);
 *     pass --workspace=ID to scope to one.
 *
 * Usage:
 *   php artisan posts:requeue-failed                 # dry-run, default 406 pattern
 *   php artisan posts:requeue-failed --apply         # commit
 *   php artisan posts:requeue-failed --match="HTTP 406" --apply
 *   php artisan posts:requeue-failed --workspace=2 --apply
 *   php artisan posts:requeue-failed --match="Metricool delivery ERROR" --allow-provider-id --workspace=1 --apply
 */
class PostsRequeueFailed extends Command
{
    /** Default error signature: the Metricool normalize HTTP 406 bug. */
    private const DEFAULT_MATCH = 'Media normalize failed';

    protected $signature = 'posts:requeue-failed
                            {--match= : last_error substring to target (default: the Metricool 406 normalize bug)}
                            {--require-406 : additionally require the literal "406" in last_error (default on for the default match)}
                            {--workspace= : restrict to one workspace id (default: all workspaces)}
                            {--allow-provider-id : permit rows that hold a provider id and clear it (ONLY for rows confirmed ERROR/FAILED on-provider — see docblock)}
                            {--apply : actually write changes (default is dry-run)}';

    protected $description = 'Requeue ScheduledPosts that failed for a transient, now-fixed provider reason (default: the Metricool media-normalise HTTP 406 bug).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $allowProviderId = (bool) $this->option('allow-provider-id');
        $match = (string) ($this->option('match') ?: self::DEFAULT_MATCH);
        // For the default match, also require "406" so we never sweep in a
        // genuine normalize failure (e.g. a 422 bad-url) that the fix didn't address.
        $require406 = $this->option('match')
            ? (bool) $this->option('require-406')
            : true;
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $query = ScheduledPost::query()
            ->where('status', 'failed')
            ->where('last_error', 'like', '%' . $match . '%');

        if ($require406) {
            $query->where('last_error', 'like', '%406%');
        }

        if ($workspaceId !== null) {
            // Scope via the brand→workspace relationship.
            $query->whereHas('brand', fn ($q) => $q->where('workspace_id', $workspaceId));
        }

        $rows = $query->with(['draft', 'brand'])->get();

        if ($rows->isEmpty()) {
            $this->info('No failed posts match — nothing to requeue.');
            $this->line('  filter: status=failed, last_error LIKE %' . $match . '%'
                . ($require406 ? ' AND %406%' : '')
                . ($workspaceId !== null ? ", workspace={$workspaceId}" : ', all workspaces'));
            return self::SUCCESS;
        }

        $stats = ['eligible' => 0, 'skipped_provider_id' => 0, 'requeued' => 0, 'cleared_provider_id' => 0];
        $byWorkspace = [];

        foreach ($rows as $sp) {
            // Guard: a row that already holds a real provider id must NOT be
            // resubmitted — that risks a duplicate. It should be polled instead.
            // EXCEPTION: --allow-provider-id revives the ERROR-after-accept class
            // (provider accepted, then delivery failed → nothing live) by clearing
            // the id so the dispatcher does a fresh submit, not a poll.
            $providerId = (string) ($sp->blotato_post_id ?? '');
            $hasProviderId = $providerId !== '' && $providerId !== 'pending';
            if ($hasProviderId && ! $allowProviderId) {
                $this->warn(sprintf('SP%d: SKIP — holds provider id "%s" (poll, do not resubmit; pass --allow-provider-id if confirmed ERROR/FAILED on-provider).', $sp->id, $providerId));
                $stats['skipped_provider_id']++;
                continue;
            }

            $stats['eligible']++;
            $wsId = $sp->brand?->workspace_id ?? 0;
            $byWorkspace[$wsId] = ($byWorkspace[$wsId] ?? 0) + 1;

            $this->line(sprintf(
                'SP%d (%s, ws#%s, attempts=%d) → queued%s',
                $sp->id,
                $sp->draft?->platform ?? '?',
                $wsId,
                $sp->attempt_count,
                $hasProviderId ? " [clearing provider id \"{$providerId}\" → fresh submit]" : '',
            ));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($sp, $hasProviderId, &$stats) {
                $update = [
                    'status' => 'queued',
                    'attempt_count' => 0,
                    'last_error' => null,
                ];
                if ($hasProviderId) {
                    // Clear the accepted-then-errored provider id so dispatch does
                    // a clean submit rather than polling a dead delivery.
                    $update['blotato_post_id'] = null;
                    $stats['cleared_provider_id']++;
                }
                $sp->update($update);
                $stats['requeued']++;
            });
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line('matched failed rows:        ' . $rows->count());
        $this->line('eligible:                   ' . $stats['eligible']);
        $this->line('skipped (has provider id):  ' . $stats['skipped_provider_id']);
        $this->line('requeued:                   ' . $stats['requeued']);
        if ($allowProviderId) {
            $this->line('provider ids cleared:       ' . $stats['cleared_provider_id']);
        }
        if (! empty($byWorkspace)) {
            $this->line('eligible by workspace:      '
                . collect($byWorkspace)->map(fn ($n, $ws) => "ws#{$ws}={$n}")->implode(', '));
        }
        $this->line('');

        if (! $apply) {
            $this->warn('DRY-RUN — nothing written. Re-run with --apply to commit.');
        } else {
            $this->info('Requeued rows will be picked up by posts:dispatch-due on the next cron tick (≤1 min).');
        }

        return self::SUCCESS;
    }
}
