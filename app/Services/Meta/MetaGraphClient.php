<?php

namespace App\Services\Meta;

use App\Services\Secrets\InfisicalResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Typed wrapper around the Meta Graph API for first-party POST ANALYTICS.
 *
 * Reference: https://developers.facebook.com/docs/instagram-platform/insights
 *
 * Scope (Phase 1 — HQ only): reads insights for Instagram/Facebook media on
 * accounts HQ OWNS. Meta grants this under STANDARD ACCESS (no App Review)
 * when the app serves only owned/managed accounts added to the App Dashboard.
 * Auth is a Business Manager SYSTEM USER token — permanent, server-to-server,
 * no recurring user re-login. Verified against Meta docs 2026-05-30.
 *
 * Customer accounts (Advanced Access + per-customer OAuth) are a later phase;
 * this client takes the token as a constructor arg precisely so a future
 * per-connection OAuth token can be passed in without changing the client.
 *
 * Requirements for the token's accounts (enforced Meta-side, surfaced here as
 * permission errors): IG account must be Business/Creator and linked to a
 * Facebook Page; scopes instagram_basic + instagram_manage_insights +
 * pages_read_engagement.
 */
class MetaGraphClient
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
        private readonly int $timeout = 30,
    ) {
        if (trim($accessToken) === '') {
            throw new RuntimeException(
                'MetaGraphClient: access token is empty. Provision the Business Manager '
                . 'System User token at the Infisical handle referenced by '
                . 'config services.meta.graph.system_user_token, or pass a per-connection token.'
            );
        }
    }

    /**
     * Construct from config using HQ's System User token. Returns null when no
     * token is configured — callers treat that as "Meta provider disabled"
     * and fall back to Blotato/CSV rather than erroring. The raw token is
     * resolved from its `secret://` handle the same way every other EIAAW
     * secret is: SecretsServiceProvider swaps the handle for the value at boot,
     * so config('services.meta.graph.system_user_token') is already the real
     * token here. We additionally tolerate an un-swapped handle (local dev
     * with the resolver off) by resolving it on demand.
     */
    public static function hqFromConfig(): ?self
    {
        $token = (string) config('services.meta.graph.system_user_token', '');

        // Defensive: if the value is still a secret:// handle (resolver off in
        // this environment), try to resolve it. If that fails, treat as absent.
        if (str_starts_with($token, 'secret://')) {
            try {
                $token = (string) app(InfisicalResolver::class)->resolve($token);
            } catch (\Throwable) {
                return null;
            }
        }

        if (trim($token) === '') {
            return null;
        }

        return new self(
            accessToken: $token,
            baseUrl: rtrim((string) config('services.meta.graph.base_url', 'https://graph.facebook.com'), '/'),
            apiVersion: (string) config('services.meta.graph.api_version', 'v21.0'),
            timeout: (int) config('services.meta.graph.request_timeout', 30),
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/' . $this->apiVersion)
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }

    /**
     * GET /{ig-media-id}/insights — engagement metrics for one IG media object.
     *
     * @param  string  $mediaId  the platform media id (Instagram media id) —
     *                           this is what we store as scheduled_posts.platform_post_id
     * @param  array<int,string>  $metrics  metric names to request. Defaults to
     *                           the broadly-available set; reels/video support
     *                           extra ones the caller can pass.
     *
     * Returns a discriminated result so the collector can distinguish
     * "real data" from "not available yet" from "permission/error" WITHOUT a
     * try/catch at the call site fabricating zeros:
     *   ['found'=>true,  'metrics'=>['reach'=>123,…], 'raw'=>…]
     *   ['found'=>false, 'reason'=>'no_data'|'permission'|'http_error', 'raw'=>…]
     *
     * @return array{found:bool, metrics?:array<string,int>, reason?:string, raw:mixed}
     */
    public function getMediaInsights(string $mediaId, array $metrics = [
        'reach', 'impressions', 'likes', 'comments', 'shares', 'saved', 'views',
    ]): array {
        $response = $this->client()->get(urlencode($mediaId) . '/insights', [
            'metric' => implode(',', $metrics),
        ]);

        // Meta returns 400 with an error subcode when a metric isn't supported
        // for that media type (e.g. 'views' on a static image) or when the
        // media is too new to have insights. We retry once with the safe
        // subset before giving up, so one unsupported metric doesn't blank the
        // whole pull.
        if ($response->status() === 400 && $metrics !== self::SAFE_METRICS) {
            return $this->getMediaInsights($mediaId, self::SAFE_METRICS);
        }

        if (! $response->successful()) {
            return [
                'found' => false,
                'reason' => $this->classifyError($response),
                'raw' => $response->json() ?? $response->body(),
            ];
        }

        $data = $response->json('data');
        if (! is_array($data) || $data === []) {
            return ['found' => false, 'reason' => 'no_data', 'raw' => $response->json()];
        }

        // Meta shape: data: [ {name, period, values:[{value:N}]}, … ]
        $flat = [];
        foreach ($data as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if (is_string($name) && is_numeric($value)) {
                $flat[$name] = (int) $value;
            }
        }

        if ($flat === []) {
            return ['found' => false, 'reason' => 'no_data', 'raw' => $response->json()];
        }

        return ['found' => true, 'metrics' => $flat, 'raw' => $response->json()];
    }

    /** The always-safe metric subset (available across media types). */
    private const SAFE_METRICS = ['reach', 'likes', 'comments', 'saved'];

    /**
     * Map a non-2xx Graph response to a coarse reason. We don't need Meta's
     * full error taxonomy — just enough for the collector to decide between
     * "skip quietly" (no data / brand-new media) and "log loudly" (permission
     * / token problem the operator must fix).
     */
    private function classifyError(Response $response): string
    {
        $code = $response->json('error.code');
        // 10 / 200 / 803 = permission or object-access problems → operator action.
        if (in_array($code, [10, 200, 803], true) || $response->status() === 403) {
            return 'permission';
        }
        if ($response->status() === 404) {
            return 'no_data';
        }
        return 'http_error';
    }

    /**
     * Connectivity probe — GET /me with the token. True if Meta accepts it.
     * Used by setup/readiness to surface a bad/expired token clearly.
     */
    public function ping(): bool
    {
        try {
            return $this->client()->get('me', ['fields' => 'id'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
