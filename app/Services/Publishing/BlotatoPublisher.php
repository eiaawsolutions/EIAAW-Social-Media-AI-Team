<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Support\Facades\Log;

/**
 * Legacy publisher via Blotato. DORMANT after the Metricool switch
 * (PUBLISH_PROVIDER=metricool by default) but kept as the rollback path until
 * Blotato is fully decommissioned in a follow-up PR.
 *
 * This wraps the per-workspace Blotato flow that previously lived inline in
 * SubmitScheduledPost: forWorkspace() → uploadMediaFromUrl → createPost →
 * getPostStatus, with the same PostVerificationRules gate before reporting
 * published.
 */
class BlotatoPublisher implements Publisher
{
    public function key(): string
    {
        return 'blotato';
    }

    public function submit(ScheduledPost $post, string $caption, array $mediaUrls): PublishResult
    {
        $client = $this->client($post);
        if ($client === null) {
            return PublishResult::failed(
                'Workspace has no Blotato API key configured (and PUBLISH_PROVIDER=blotato).'
            );
        }

        $blotatoMedia = [];
        foreach ($mediaUrls as $url) {
            try {
                $blotatoMedia[] = $client->uploadMediaFromUrl($url);
            } catch (\Throwable $e) {
                return PublishResult::failed('Media upload failed: ' . substr($e->getMessage(), 0, 200));
            }
        }

        $platform = $post->draft?->platform === 'x' ? 'twitter' : (string) $post->draft?->platform;
        $overrides = is_array($post->platformConnection?->target_overrides)
            ? $post->platformConnection->target_overrides
            : [];

        try {
            $submissionId = $client->createPost(
                accountId: (string) $post->platformConnection?->blotato_account_id,
                platform: $platform,
                text: $caption,
                mediaUrls: $blotatoMedia,
                scheduledTime: null,
                targetOverrides: $overrides,
            );
        } catch (\Throwable $e) {
            return PublishResult::failed('Blotato createPost failed: ' . substr($e->getMessage(), 0, 200));
        }

        return PublishResult::submitted($submissionId);
    }

    public function poll(ScheduledPost $post): PublishResult
    {
        if (! $post->blotato_post_id) {
            return PublishResult::pending();
        }
        $client = $this->client($post);
        if ($client === null) {
            return PublishResult::pending();
        }

        try {
            $status = $client->getPostStatus($post->blotato_post_id);
        } catch (\Throwable $e) {
            Log::warning('BlotatoPublisher: poll failed (will retry)', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return PublishResult::pending();
        }

        $state = strtolower((string) ($status['state'] ?? $status['status'] ?? ''));
        $platformPostId = $this->dig($status, ['postId', 'post_id', 'platformPostId', 'externalId', 'id']);
        $platformPostUrl = $this->dig($status, ['publicUrl', 'public_url', 'postUrl', 'post_url', 'platformPostUrl', 'permalink', 'url', 'shareUrl']);
        $error = $status['error'] ?? $status['message'] ?? null;

        if (in_array($state, ['published', 'success', 'completed'], true)) {
            $platform = (string) ($post->draft?->platform ?? '');
            $verdict = PostVerificationRules::verify($platform, $platformPostId, $platformPostUrl);
            if (! $verdict['verified']) {
                return PublishResult::pending(raw: $status);
            }
            return PublishResult::published($platformPostId, $platformPostUrl, $status);
        }

        if (in_array($state, ['failed', 'error', 'rejected'], true)) {
            $msg = trim((string) $error) ?: ('Platform rejected (' . $state . ')');
            return PublishResult::failed(substr($msg, 0, 220), $status);
        }

        return PublishResult::pending(raw: $status);
    }

    private function client(ScheduledPost $post): ?BlotatoClient
    {
        $workspace = $post->brand?->workspace;
        if (! $workspace) {
            return null;
        }
        try {
            return BlotatoClient::forWorkspace($workspace);
        } catch (\Throwable $e) {
            Log::warning('BlotatoPublisher: client init failed', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>    $keys
     */
    private function dig(array $payload, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (! empty($payload[$k]) && is_string($payload[$k])) {
                return $payload[$k];
            }
        }
        foreach (['result', 'data', 'post', 'submission'] as $env) {
            if (isset($payload[$env]) && is_array($payload[$env])) {
                foreach ($keys as $k) {
                    if (! empty($payload[$env][$k]) && is_string($payload[$env][$k])) {
                        return $payload[$env][$k];
                    }
                }
            }
        }
        return null;
    }
}
