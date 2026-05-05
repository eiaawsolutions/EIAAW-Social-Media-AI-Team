<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use App\Services\Publishing\PostVerificationRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-verifies every ScheduledPost currently marked `published` against
 * Blotato's actual status, then either:
 *   - confirms (saves the real platform_post_id / platform_post_url Blotato
 *     now returns, if it didn't before), or
 *   - downgrades to `submitted` so the live feed stops claiming a post
 *     exists when the platform has no record of it.
 *
 * Background: PostVerificationRules now requires either platform_post_id or
 * a real-post-URL pattern to consider a post verified-published. We have
 * 32 historical rows in prod marked `published` (2026-05-03 → 2026-05-06)
 * that pre-date the verification gate. Most have null/empty platform_post_id
 * and only a profile-root URL (or no URL at all). This command cleans them
 * up by going back to Blotato as the source of truth for each.
 *
 * Operator UX: dry-run by default surface counts without touching DB. Pass
 * --apply to write changes. Re-running is idempotent — verified rows stay
 * verified, downgraded rows stay submitted (no double-downgrade churn).
 */
class PostsReconcilePublished extends Command
{
    protected $signature = 'posts:reconcile-published
                            {--limit=200 : max rows to re-verify per run}
                            {--platform= : limit to one platform (tiktok, youtube, etc.)}
                            {--apply : actually write changes (default is dry-run)}';

    protected $description = 'Re-verify published ScheduledPosts against Blotato — downgrade unverified rows to submitted.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $platformFilter = strtolower(trim((string) $this->option('platform'))) ?: null;
        $apply = (bool) $this->option('apply');

        try {
            $client = BlotatoClient::fromConfig();
        } catch (\Throwable $e) {
            $this->error('Blotato config error: ' . $e->getMessage());
            return self::FAILURE;
        }

        $q = ScheduledPost::with('draft')
            ->where('status', 'published')
            ->whereNotNull('blotato_post_id')
            ->orderBy('id')
            ->limit($limit);

        if ($platformFilter !== null) {
            $q->whereHas('draft', fn ($d) => $d->where('platform', $platformFilter));
        }

        $rows = $q->get();
        if ($rows->isEmpty()) {
            $this->info('Nothing to reconcile.');
            return self::SUCCESS;
        }

        $stats = ['checked' => 0, 'verified_pre_existing' => 0, 'verified_after_refresh' => 0,
                  'downgraded' => 0, 'blotato_missing' => 0, 'errors' => 0];

        foreach ($rows as $post) {
            $stats['checked']++;
            $platform = (string) ($post->draft?->platform ?? '');

            // Already verified by the new rules? Skip — no need to call Blotato.
            $existing = PostVerificationRules::verify($platform, $post->platform_post_id, $post->platform_post_url);
            if ($existing['verified']) {
                $stats['verified_pre_existing']++;
                $this->line(sprintf('SP%d %s — already verified (%s)', $post->id, $platform, $existing['reason']));
                continue;
            }

            // Re-fetch from Blotato. Some posts that were unverified at first
            // poll later resolve to a real id/url once the platform adapter
            // catches up, so a fresh fetch can save them.
            try {
                $status = $client->getPostStatus($post->blotato_post_id);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn(sprintf('SP%d Blotato status fetch failed: %s', $post->id, substr($e->getMessage(), 0, 120)));
                continue;
            }

            $state = strtolower((string) ($status['state'] ?? $status['status'] ?? ''));
            $newId = $this->digKeys($status, ['postId', 'post_id', 'platformPostId', 'externalId', 'id']);
            $newUrl = $this->digKeys($status, ['postUrl', 'post_url', 'platformPostUrl', 'permalink', 'url', 'shareUrl', 'share_url']);

            $verdict = PostVerificationRules::verify($platform, $newId, $newUrl);

            if ($verdict['verified']) {
                $stats['verified_after_refresh']++;
                $this->info(sprintf('SP%d %s → verified after refresh (%s)', $post->id, $platform, $verdict['reason']));
                if ($apply) {
                    $post->update([
                        'platform_post_id' => $newId,
                        'platform_post_url' => $newUrl,
                    ]);
                }
                continue;
            }

            // Blotato itself can't confirm a real platform delivery.
            $stats['downgraded']++;
            $this->warn(sprintf(
                'SP%d %s → downgrading: state=%s, %s [Blotato keys: %s]',
                $post->id, $platform, $state ?: '(empty)', $verdict['reason'],
                implode(',', array_keys($status)),
            ));
            if ($apply) {
                $post->update([
                    'status' => 'submitted',
                    'platform_post_id' => null,
                    // Wipe profile-root URLs but keep anything that looked like a real post.
                    'platform_post_url' => PostVerificationRules::isRealPostUrl($platform, $post->platform_post_url)
                        ? $post->platform_post_url
                        : null,
                    'published_at' => null,
                    'last_error' => sprintf(
                        'Reconciled %s: %s. Awaiting platform-side confirmation.',
                        now()->toIso8601String(),
                        $verdict['reason'],
                    ),
                ]);
                // Don't auto-flip the linked draft — the operator may have
                // human-verified status on it via /agency/drafts.
            }
        }

        $this->line('');
        $this->line('--- summary ---');
        $this->line("checked:                     {$stats['checked']}");
        $this->line("already verified:            {$stats['verified_pre_existing']}");
        $this->line("verified after refresh:      {$stats['verified_after_refresh']}");
        $this->line("downgraded to submitted:     {$stats['downgraded']}");
        $this->line("errors (Blotato unreachable): {$stats['errors']}");
        $this->line('');
        if (! $apply) {
            $this->warn('DRY-RUN — nothing written. Re-run with --apply to commit changes.');
        }
        return self::SUCCESS;
    }

    /**
     * Mirror of SubmitScheduledPost::digKeys — walks Blotato envelopes for
     * the first non-empty string under any candidate key.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>    $keys
     */
    private function digKeys(array $payload, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (! empty($payload[$k]) && is_string($payload[$k])) {
                return $payload[$k];
            }
        }
        foreach (['result', 'data', 'post', 'submission'] as $envelope) {
            if (isset($payload[$envelope]) && is_array($payload[$envelope])) {
                foreach ($keys as $k) {
                    if (! empty($payload[$envelope][$k]) && is_string($payload[$envelope][$k])) {
                        return $payload[$envelope][$k];
                    }
                }
            }
        }
        return null;
    }
}
