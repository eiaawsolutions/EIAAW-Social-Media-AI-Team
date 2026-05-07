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
                // Blotato REQUIRES post.target on every submission, with
                // per-platform required fields. Build the safe default
                // and let the caller override individual keys via
                // $targetOverrides.
                'target' => $this->defaultTargetFor($platform, $accountId, $text, $targetOverrides),
            ],
        ];

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
     * Per-platform `target` defaults that satisfy Blotato's required fields.
     * Source of truth: backend.blotato.com/openapi.json (verified 2026-05-02).
     *
     * Required-by-platform:
     *   linkedin   → targetType + pageId       (pageId = the Blotato account id)
     *   facebook   → targetType + pageId       (same)
     *   pinterest  → targetType + boardId
     *   tiktok     → targetType + 7 flags (privacyLevel + 6 booleans)
     *   youtube    → targetType + title + privacyStatus + shouldNotifySubscribers
     *   instagram  → targetType only
     *   threads    → targetType only
     *   twitter/x  → targetType only ('twitter' is Blotato's targetType for X)
     *
     * Defaults for first-time posts are conservative: TikTok privacy=SELF_ONLY
     * so test posts aren't visible to followers; YouTube privacyStatus=private.
     * Operator overrides via $targetOverrides at the call site once they want
     * public publishing.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function defaultTargetFor(string $platform, string $accountId, string $text, array $overrides): array
    {
        // For LinkedIn + Facebook: Blotato's `pageId` is OPTIONAL when the
        // connected account is a personal profile — Blotato routes to the
        // profile automatically when pageId is absent. Setting pageId to
        // the accountId fails with HTTP 422 "Page / subaccount not found".
        // Operator must explicitly pass overrides.pageId for Company Pages
        // / Facebook Pages (use the platform-side numeric id, NOT the
        // Blotato accountId). Verified live against api 2026-05-03 with a
        // successful publish to a personal LinkedIn profile via no-pageId.
        $base = match ($platform) {
            'linkedin' => [
                'targetType' => 'linkedin',
            ],
            'facebook' => [
                'targetType' => 'facebook',
            ],
            'pinterest' => [
                'targetType' => 'pinterest',
                // Pinterest is the one platform where boardId is genuinely
                // required (no personal-fallback). Operator must supply via
                // overrides; empty default surfaces a clear "missing boardId"
                // error instead of silent wrong-board posting.
                'boardId' => $overrides['boardId'] ?? '',
            ],
            'tiktok' => [
                'targetType' => 'tiktok',
                // 2026-05-07: flipped from SELF_ONLY → PUBLIC_TO_EVERYONE per
                // operator decision. Posts are now visible on the public feed
                // immediately. Operator can still narrow per-connection via
                // platform_connections.target_overrides.privacyLevel
                // (PUBLIC_TO_EVERYONE | MUTUAL_FOLLOW_FRIENDS | FOLLOWER_OF_CREATOR | SELF_ONLY).
                'privacyLevel' => 'PUBLIC_TO_EVERYONE',
                'disabledComments' => false,
                'disabledDuet' => false,
                'disabledStitch' => false,
                'isBrandedContent' => false,
                'isYourBrand' => false,
                'isAiGenerated' => true, // we ARE generating with AI; truth in compliance
            ],
            'youtube' => [
                'targetType' => 'youtube',
                'title' => $this->extractYoutubeTitle($text),
                // 2026-05-07: flipped from private → public per operator
                // decision. Operator can override per-connection via
                // platform_connections.target_overrides.privacyStatus
                // (public | unlisted | private).
                'privacyStatus' => 'public',
                // Subscribers get notified on first publish — surfaces the
                // post in their YT app inbox. Operator can override.
                'shouldNotifySubscribers' => true,
                'isMadeForKids' => false,
                'containsSyntheticMedia' => true, // we're posting AI-generated
            ],
            'twitter', 'x' => [
                // Blotato's targetType for X is 'twitter' (legacy naming).
                'targetType' => 'twitter',
            ],
            'instagram' => [
                'targetType' => 'instagram',
            ],
            'threads' => [
                'targetType' => 'threads',
                'replyControl' => 'everyone', // safest default — operator restricts via overrides
            ],
            default => [
                'targetType' => $platform,
            ],
        };

        return array_merge($base, $overrides);
    }

    /**
     * YouTube requires a title. We take the first line of the caption,
     * truncated to 90 chars (the YouTube cap is 100; leave headroom).
     */
    private function extractYoutubeTitle(string $text): string
    {
        $first = strtok($text, "\n") ?: $text;
        return mb_substr(trim($first), 0, 90);
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
