<?php

namespace App\Services\Blotato;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Typed wrapper around Blotato's HTTP API.
 *
 * Reference: https://backend.blotato.com/openapi.json (v2.0.0).
 *
 * Auth: custom header `blotato-api-key: blt_xxx` (NOT Authorization: Bearer).
 *       The API key resolves from config('services.blotato.api_key'), which
 *       SecretsServiceProvider has already swapped from a `secret://` handle
 *       into the real value at boot. Caller code never touches env directly.
 *
 * v1 surface we use:
 *   - GET  /v2/users/me/accounts          — list connected social accounts
 *   - POST /v2/media                       — upload media from URL
 *   - POST /v2/posts                       — create + schedule a post
 *   - GET  /v2/posts/{postSubmissionId}    — poll post status
 *
 * v1 surface we explicitly skip:
 *   - /v2/schedules/* (we own scheduling in Postgres via ScheduledPost)
 *   - /v2/source-resolutions-v3/* (URL resolver — not needed for our flow)
 *   - /v2/videos/* (we generate via FAL.AI, not Blotato's templates)
 *
 * Connection lifecycle: customers connect platforms inside Blotato's web UI
 * (we don't have an OAuth-redirect flow for that). We poll listAccounts() to
 * discover what they've connected, then store rows in our platform_connections
 * table referencing each Blotato accountId.
 */
class BlotatoClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {
        if ($apiKey === '' || ! str_starts_with($apiKey, 'blt_')) {
            throw new RuntimeException(
                "BlotatoClient: api key must start with 'blt_' (got " . substr($apiKey, 0, 4) . '...). '
                . 'Check Infisical at eiaaw-smt-prod/prod/BLOTATO_API_KEY and SecretsServiceProvider resolution.'
            );
        }
    }

    /**
     * Construct from Laravel config — single source of truth for app code.
     * Equivalent to `app(BlotatoClient::class)` if registered as a singleton,
     * but keeps construction explicit + testable.
     */
    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('services.blotato.api_key'),
            baseUrl: rtrim((string) config('services.blotato.base_url', 'https://backend.blotato.com'), '/'),
            timeout: (int) config('services.blotato.request_timeout', 30),
        );
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
                'blotato-api-key' => $this->apiKey,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ])
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }

    /**
     * GET /v2/users/me/accounts[?platform=X]
     *
     * Returns the list of social accounts the user (= Blotato workspace) has
     * connected. Each item:
     *   { id: string, platform: 'twitter'|'instagram'|..., fullname: string, username: string }
     *
     * @return array<int, array{id:string, platform:string, fullname:string, username:string}>
     *
     * @throws RuntimeException on non-200 / shape error
     */
    public function listAccounts(?string $platform = null): array
    {
        $query = [];
        if ($platform !== null) {
            $query['platform'] = $platform;
        }

        $response = $this->client()->get('/v2/users/me/accounts', $query);
        $this->throwIfError($response, 'listAccounts');

        $items = $response->json('items');
        if (! is_array($items)) {
            throw new RuntimeException('Blotato listAccounts: response missing "items" array. Body: ' . $response->body());
        }

        return $items;
    }

    /**
     * GET /v2/users/me — current Blotato user info. Used as a connectivity
     * probe (smoke test in BlotatoClient::ping()).
     */
    public function getCurrentUser(): array
    {
        $response = $this->client()->get('/v2/users/me');
        $this->throwIfError($response, 'getCurrentUser');
        return $response->json() ?? [];
    }

    /**
     * Connectivity smoke test. Returns true if the API key is valid and the
     * service is reachable. Used during platforms:sync to surface auth
     * failures clearly before the sync logic gets into ambiguous states.
     */
    public function ping(): bool
    {
        try {
            $this->getCurrentUser();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * POST /v2/media — upload media from URL. Returns the Blotato-hosted URL
     * that must be passed to /v2/posts (Blotato rejects mediaUrls that don't
     * originate from its own domain).
     */
    public function uploadMediaFromUrl(string $url): string
    {
        $response = $this->client()->post('/v2/media', ['url' => $url]);
        $this->throwIfError($response, 'uploadMediaFromUrl');

        $blotatoUrl = $response->json('url') ?? $response->json('mediaUrl');
        if (! is_string($blotatoUrl) || $blotatoUrl === '') {
            throw new RuntimeException('Blotato uploadMediaFromUrl: response missing "url". Body: ' . $response->body());
        }

        return $blotatoUrl;
    }

    /**
     * POST /v2/posts — create a post submission.
     *
     * @param  string  $accountId       Blotato account id (from listAccounts)
     * @param  string  $platform        twitter|instagram|linkedin|facebook|tiktok|pinterest|threads|bluesky|youtube
     * @param  string  $text            caption / body text
     * @param  array<int,string>  $mediaUrls Blotato-hosted media URLs (from uploadMediaFromUrl)
     * @param  ?string $scheduledTime   ISO 8601 string; null = post now
     * @param  array   $targetOverrides per-platform `target` block overrides (TikTok privacy_level, etc)
     *
     * Returns the postSubmissionId (string UUID) for status polling.
     *
     * @throws RuntimeException on non-201
     */
    public function createPost(
        string $accountId,
        string $platform,
        string $text,
        array $mediaUrls = [],
        ?string $scheduledTime = null,
        array $targetOverrides = [],
    ): string {
        $body = [
            'post' => [
                'accountId' => $accountId,
                'content' => [
                    'text' => $text,
                    'mediaUrls' => $mediaUrls,
                    'platform' => $platform,
                ],
            ],
        ];

        if ($targetOverrides !== []) {
            $body['post']['target'] = array_merge(
                ['targetType' => $platform],
                $targetOverrides,
            );
        }

        if ($scheduledTime !== null) {
            $body['scheduledTime'] = $scheduledTime;
        }

        $response = $this->client()->post('/v2/posts', $body);
        $this->throwIfError($response, 'createPost');

        $id = $response->json('postSubmissionId');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Blotato createPost: response missing "postSubmissionId". Body: ' . $response->body());
        }

        return $id;
    }

    /**
     * GET /v2/posts/{postSubmissionId} — get current status of a submitted post.
     * Returns the raw response body; SchedulerAgent interprets it.
     */
    public function getPostStatus(string $postSubmissionId): array
    {
        $response = $this->client()->get('/v2/posts/' . urlencode($postSubmissionId));
        $this->throwIfError($response, 'getPostStatus');
        return $response->json() ?? [];
    }

    private function throwIfError(Response $response, string $op): void
    {
        if ($response->successful()) {
            return;
        }

        $msg = sprintf(
            'Blotato %s failed: HTTP %d — %s',
            $op,
            $response->status(),
            substr($response->body(), 0, 500),
        );
        throw new RuntimeException($msg);
    }
}
