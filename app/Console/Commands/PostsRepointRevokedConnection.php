<?php

namespace App\Console\Commands;

use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovery command for posts blocked by "Platform connection is not active
 * (status=revoked)" when an ACTIVE connection exists for the same brand +
 * platform.
 *
 * THE DATA PROBLEM this heals (Blotato→Metricool migration residue): brand #1
 * (and any migrated brand) has TWO platform_connections per network — the old
 * Blotato-era row (carries blotato_account_id, marked status=revoked at the
 * cutover) and the new Metricool-era row (status=active). Scheduled posts
 * created before the cutover still point platform_connection_id at the OLD
 * revoked row, so SubmitScheduledPost's `status !== 'active'` gate refuses them
 * even though a perfectly good active connection exists for the same
 * brand+platform. The post's media/caption are fine — only the FK is stale.
 *
 * WHAT IT DOES per matched ScheduledPost (status=failed, last_error mentions
 * the revoked-connection gate):
 *   1. Look up an ACTIVE PlatformConnection for the same brand_id + the draft's
 *      platform.
 *   2. If found, repoint platform_connection_id to it and requeue
 *      (status=queued, attempt_count=0, clear last_error).
 *   3. If no active sibling exists, leave the post failed (the connection
 *      genuinely needs reconnecting — not something we can heal by repointing).
 *
 * SAFETY:
 *   - Only repoints to an ACTIVE connection of the SAME brand + platform, so a
 *     post can never cross to another brand's account (the tenant-isolation
 *     guarantee). Mirrors MetricoolPublisher's own brand-scoped targeting.
 *   - Skips posts holding a real provider id (no double-post).
 *   - Dry-run by default; --apply to write. --workspace scopes to one.
 *
 * Usage:
 *   php artisan posts:repoint-revoked-connection                 # dry-run
 *   php artisan posts:repoint-revoked-connection --apply
 *   php artisan posts:repoint-revoked-connection --workspace=2 --apply
 */
class PostsRepointRevokedConnection extends Command
{
    /** The exact gate signature from SubmitScheduledPost. */
    private const SIGNATURE = 'connection is not active';

    protected $signature = 'posts:repoint-revoked-connection
                            {--workspace= : restrict to one workspace id (default: all)}
                            {--apply : actually repoint + requeue (default is dry-run)}';

    protected $description = 'Repoint posts stuck on a revoked platform_connection to the active connection for the same brand+platform, then requeue.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $query = ScheduledPost::query()
            ->where('status', 'failed')
            ->where('last_error', 'like', '%' . self::SIGNATURE . '%')
            ->with(['draft', 'platformConnection', 'brand']);

        if ($workspaceId !== null) {
            $query->whereHas('brand', fn ($q) => $q->where('workspace_id', $workspaceId));
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('No posts match the revoked-connection signature — nothing to repoint.');
            return self::SUCCESS;
        }

        $stats = ['eligible' => 0, 'skipped_provider_id' => 0, 'no_active_sibling' => 0,
                  'already_active' => 0, 'repointed' => 0];

        // Cache active connection lookups per (brand,platform).
        $activeCache = [];
        $activeFor = function (int $brandId, string $platform) use (&$activeCache): ?PlatformConnection {
            $key = $brandId . '|' . $platform;
            if (! array_key_exists($key, $activeCache)) {
                $activeCache[$key] = PlatformConnection::where('brand_id', $brandId)
                    ->where('platform', $platform)
                    ->where('status', 'active')
                    ->first();
            }
            return $activeCache[$key];
        };

        foreach ($rows as $sp) {
            $providerId = (string) ($sp->blotato_post_id ?? '');
            if ($providerId !== '' && $providerId !== 'pending') {
                $this->warn(sprintf('SP%d: SKIP — holds provider id "%s" (poll, do not resubmit).', $sp->id, $providerId));
                $stats['skipped_provider_id']++;
                continue;
            }

            $platform = (string) ($sp->draft?->platform ?? '');
            $current = $sp->platformConnection;

            // Defensive: if the currently-referenced connection is already
            // active, this isn't the stale-FK case — just requeue it.
            if ($current && $current->status === 'active') {
                $stats['already_active']++;
                $this->line(sprintf('SP%d (%s): current conn#%d already active → requeue only.', $sp->id, $platform, $current->id));
                if ($apply) {
                    DB::transaction(fn () => $sp->update(['status' => 'queued', 'attempt_count' => 0, 'last_error' => null]));
                    $stats['repointed']++;
                }
                continue;
            }

            $active = $activeFor((int) $sp->brand_id, $platform);
            if (! $active) {
                $this->warn(sprintf('SP%d (%s): SKIP — no ACTIVE connection for this brand+platform; needs reconnect.', $sp->id, $platform));
                $stats['no_active_sibling']++;
                continue;
            }

            $stats['eligible']++;
            $this->line(sprintf(
                'SP%d (%s, ws#%s): conn#%s (revoked) → conn#%d (active)',
                $sp->id, $platform, $sp->brand?->workspace_id,
                $current?->id ?? '-', $active->id,
            ));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($sp, $active, &$stats) {
                $sp->update([
                    'platform_connection_id' => $active->id,
                    'status' => 'queued',
                    'attempt_count' => 0,
                    'last_error' => null,
                ]);
                $stats['repointed']++;
            });
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line('matched failed rows:         ' . $rows->count());
        $this->line('eligible (repointable):      ' . $stats['eligible']);
        $this->line('current already active:      ' . $stats['already_active']);
        $this->line('skipped (has provider id):   ' . $stats['skipped_provider_id']);
        $this->line('skipped (no active sibling): ' . $stats['no_active_sibling']);
        $this->line('repointed + requeued:        ' . $stats['repointed']);
        $this->line('');

        if (! $apply) {
            $this->warn('DRY-RUN — nothing written. Re-run with --apply to commit.');
        } else {
            $this->info('Requeued rows will be picked up by posts:dispatch-due on the next cron tick (≤1 min).');
        }

        return self::SUCCESS;
    }
}
