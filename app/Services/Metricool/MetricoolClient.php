<?php

namespace App\Services\Metricool;

use App\Services\Secrets\InfisicalResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin, READ-ONLY-LEANING wrapper around Metricool's HTTP API — built for the
 * evaluation probes (metricool:probe-metrics / metricool:probe-publish), NOT
 * yet wired into the publish/metrics pipeline. Promoting it to a real
 * collector + publisher is gated on the two audits passing. See memory
 * [[metricool-evaluation]] + [[metricool-multitenancy]].
 *
 * AUTH (verified against Metricool API docs 2026-05-30):
 *   - Custom header  X-Mc-Auth: <token>   (NOT Authorization: Bearer)
 *   - Query params   userId=<account id> & blogId=<brand id> on every call
 *   - One token covers ALL brands in the account; blogId selects the brand.
 *
 * MULTI-TENANCY (the whole reason we evaluated this): Metricool is natively
 * multi-brand. ONE account, ONE token, N brands (each a blogId). This is the
 * OPPOSITE of BlotatoClient::forWorkspace() (one account/key per workspace).
 * So this client is constructed ONCE from config and scoped per call by
 * passing the brand's blogId — there is no forWorkspace() factory here by
 * design. Isolation is a server-side discipline: always pass the correct
 * blogId. A future audit:metricool-blogid-integrity command mirrors
 * audit:blotato-leakage.
 *
 * Base URL is https://app.metricool.com/api (the public app API), distinct
 * from Blotato's backend host.
 *
 * Endpoints touched (per Metricool API docs + the community metricool-cli):
 *   - GET  /admin/simpleProfiles            — list brands (blogId + networks)
 *   - GET  /v2/analytics/posts/{network}    — per-post analytics for a network
 *   - GET  /v2/analytics/timelines          — account-level growth timeseries
 *                                             (followers/impressions over time)
 *   - POST /v2/scheduler/posts              — schedule/publish (autoPublish flag)
 *   - GET  /actions/normalize/image/url     — normalise a media URL → mediaId
 *
 * NOTE: exact analytics JSON field names per network are the ONE thing that
 * can't be confirmed from docs alone — that's what probe-metrics exists to
 * capture live. Everything here returns the raw decoded body so the probe can
 * inspect real shapes without guessing.
 */
class MetricoolClient
{
    public function __construct(
        private readonly string $apiToken,
        private readonly int $userId,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {
        if ($apiToken === '') {
            throw new RuntimeException(
                'MetricoolClient: api token is empty. Set METRICOOL_API_TOKEN to a '
                . 'secret:// handle in Infisical (resolved at boot) — see config/services.php metricool.'
            );
        }
        if ($userId <= 0) {
            throw new RuntimeException(
                'MetricoolClient: METRICOOL_USER_ID must be the numeric Metricool account id (> 0).'
            );
        }
    }

    /**
     * Build from Laravel config. SecretsServiceProvider has already swapped the
     * `secret://` handle for the real token at boot, so config holds the value.
     * Returns null (not throws) when unconfigured, so probes can no-op cleanly
     * with a clear message rather than blowing up an unprovisioned environment.
     */
    public static function fromConfig(): ?self
    {
        $token = (string) config('services.metricool.api_token', '');
        $userId = (int) config('services.metricool.user_id', 0);
        if ($token === '' || str_starts_with($token, 'secret://') || $userId <= 0) {
            // Empty, an unresolved handle (Infisical disabled locally), or no
            // user id → treat as "not configured" so the probe says so plainly.
            return null;
        }

        return new self(
            apiToken: $token,
            userId: $userId,
            baseUrl: rtrim((string) config('services.metricool.base_url', 'https://app.metricool.com/api'), '/'),
            timeout: (int) config('services.metricool.request_timeout', 30),
        );
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
                'X-Mc-Auth' => $this->apiToken,
                'accept' => 'application/json',
            ])
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }

    /**
     * Like client(), but WITHOUT the `Accept: application/json` header.
     *
     * The /actions/normalize/* endpoints respond with `text/plain` (the
     * normalised URL as a bare string), NOT JSON. Demanding application/json
     * makes Tomcat reject the request with HTTP 406 Not Acceptable — which was
     * the root cause of every "Media normalize failed … (HTTP 406)" publish
     * failure after the Blotato→Metricool switch. We send `Accept: * / *` so the
     * server is free to return its native text/plain. Verified live 2026-06-01.
     */
    private function plainClient(): PendingRequest
    {
        return Http::withHeaders([
                'X-Mc-Auth' => $this->apiToken,
                'accept' => '*/*',
            ])
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }

    /** Query params Metricool requires on (nearly) every call. */
    private function baseQuery(?int $blogId = null): array
    {
        $q = ['userId' => $this->userId];
        if ($blogId !== null) {
            $q['blogId'] = $blogId;
        }
        return $q;
    }

    /**
     * GET /admin/simpleProfiles — list all brands under this account.
     * Each brand carries its blogId and the set of connected networks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listBrands(): array
    {
        $response = $this->client()->get('/admin/simpleProfiles', $this->baseQuery());
        $this->throwIfError($response, 'listBrands');

        $json = $response->json();
        // Metricool has returned either a bare array or an enveloped {data:[…]}
        // across versions — accept both rather than assume one shape.
        if (is_array($json) && array_is_list($json)) {
            return $json;
        }
        $items = $json['data'] ?? $json['profiles'] ?? $json['items'] ?? null;
        if (is_array($items)) {
            return $items;
        }
        throw new RuntimeException('Metricool listBrands: unexpected response shape. Body: ' . $response->body());
    }

    /**
     * GET /v2/analytics/posts/{network} — per-post analytics for one network
     * within a brand over a date window. Returns the RAW decoded body so the
     * probe can map real field names onto our post_metrics columns without
     * guessing. `found=false` when the endpoint 404s (e.g. network not on plan).
     *
     * @return array{found:bool, status:int, body:mixed}
     */
    public function postAnalytics(int $blogId, string $from, string $to, string $network): array
    {
        // Metricool's analytics endpoints validate the window as `from`/`to`
        // (verified live 2026-05-30: sending start/end yields HTTP 400
        // "getInstagramPosts.from must not be null"). Dates are ISO
        // (YYYY-MM-DD); the API also accepts full dateTime.
        $query = array_merge($this->baseQuery($blogId), [
            'from' => $from,
            'to' => $to,
        ]);

        $response = $this->client()->get('/v2/analytics/posts/' . urlencode($network), $query);

        if ($response->status() === 404) {
            return ['found' => false, 'status' => 404, 'body' => $response->json()];
        }
        $this->throwIfError($response, "postAnalytics({$network})");

        return ['found' => true, 'status' => $response->status(), 'body' => $response->json()];
    }

    /**
     * GET /admin/profile?blogId=&userId= — the brand's full profile, including
     * which social networks are connected (verified live 2026-05-30). Each
     * network key holds the connected account handle/id when connected, or null
     * when not. This is how the onboarding flow DETECTS that a customer has
     * connected their socials via the share-link — no manual "verify" guessing.
     *
     * Returns the raw decoded body (a flat object keyed by network). Used by
     * MetricoolConnectionService to derive connected-network state and to
     * populate platform_connections.
     *
     * @return array{found:bool, status:int, body:array<string,mixed>}
     */
    public function getProfile(int $blogId, bool $refreshCache = false): array
    {
        $query = array_merge($this->baseQuery($blogId), [
            'refreshBrandCache' => $refreshCache ? 'true' : 'false',
        ]);
        $response = $this->client()->get('/admin/profile', $query);

        if ($response->status() === 404) {
            return ['found' => false, 'status' => 404, 'body' => []];
        }
        $this->throwIfError($response, 'getProfile');

        $body = $response->json();
        return ['found' => true, 'status' => $response->status(), 'body' => is_array($body) ? $body : []];
    }

    /**
     * GET /v2/analytics/timelines — ACCOUNT-LEVEL timeseries for one metric on
     * one network over a date window. This is the data behind Metricool's
     * "Account" growth view (followers over time, impressions over time, per
     * network) — distinct from the per-post analytics in postAnalytics().
     *
     * VERIFIED LIVE against prod blogId 6322515 (2026-05-31). This REPLACES an
     * earlier wrong attempt at `/stats/timeline/{metric}` — that is a legacy
     * stub that returns `[["date","0"]]` for EVERY metric (even invalid ones),
     * so it silently produced all-zeros for data that plainly exists in the UI.
     * The real endpoint Metricool's own dashboard uses is this one.
     *
     * Contract (all confirmed by the live 400/200 responses):
     *   - Required query params: `metric`, `network`, `subject`, `from`, `to`
     *     (+ userId/blogId for multi-tenant scoping).
     *   - `subject` is the timeline family — for account growth it is 'account'
     *     (other valid subjects: reels, posts, stories, competitors).
     *   - `from`/`to` are ISO datetime (YYYY-MM-DD'T'HH:mm:ss), same as the
     *     /v2/analytics/posts endpoints — NOT the compact YYYYMMDD the legacy
     *     /stats endpoint wanted.
     *   - `metric` is network-specific and CASE-SENSITIVE. The valid enum is
     *     surfaced by the API itself on an invalid value; AccountGrowthService
     *     holds the verified per-network map. Examples that returned real data:
     *       instagram followers → Followers (=7, matches UI)   impressions → impressions
     *       facebook  followers → Follows                       impressions → pageImpressions
     *       linkedin  followers → Followers (=12, matches UI)
     *       tiktok    followers → followers_count (=3)          views → video_views
     *       youtube   followers → totalSubscribers (=1)         views → views (=13, matches UI)
     *       threads   followers → followers_count (=2)
     *   - Response shape: {"data":[{"metric":"<name>","values":[{"dateTime":ISO,"value":float}, …]}]}.
     *
     * Returns a discriminated result so callers never have to guess shape:
     *   ['found'=>true,  'points'=>[['date'=>'YYYY-MM-DD','value'=>int|float], …]]
     *   ['found'=>false, 'status'=>int]   (404 not-connected / 400 bad-metric / 500)
     *
     * A 400 (invalid metric/missing connection) or 500 is treated as
     * found=false rather than thrown — the caller degrades that one network to a
     * "not available" tile instead of failing the whole dashboard.
     *
     * @return array{found:bool, status:int, points:array<int,array{date:string,value:int|float}>}
     */
    public function getAccountTimeline(
        int $blogId,
        string $metric,
        string $network,
        string $fromIso,
        string $toIso,
        string $subject = 'account',
    ): array {
        $query = array_merge($this->baseQuery($blogId), [
            'metric' => $metric,
            'network' => $network,
            'subject' => $subject,
            'from' => $fromIso,
            'to' => $toIso,
        ]);

        $response = $this->client()->get('/v2/analytics/timelines', $query);

        // Not-connected (404), invalid metric for this network (400), or an
        // upstream 500 → this network simply has no series; don't blow up the
        // whole board. Only a truly unexpected status throws.
        if (in_array($response->status(), [400, 404, 500], true)) {
            return ['found' => false, 'status' => $response->status(), 'points' => []];
        }
        $this->throwIfError($response, "getAccountTimeline({$network}/{$metric})");

        return [
            'found' => true,
            'status' => $response->status(),
            'points' => $this->parseTimelinePoints($response->json(), $metric),
        ];
    }

    /**
     * Parse Metricool's {data:[{metric,values:[{dateTime,value}]}]} timeline body
     * into [{date,value}] points. Picks the series matching $metric (the API
     * returns one entry, but be defensive). Non-numeric values are dropped, never
     * coerced to 0 (Truthfulness Contract: a missing reading is missing, not 0).
     *
     * @return array<int,array{date:string,value:int|float}>
     */
    private function parseTimelinePoints(mixed $body, string $metric): array
    {
        if (! is_array($body)) {
            return [];
        }

        $series = $body['data'] ?? null;
        if (! is_array($series)) {
            return [];
        }

        // Prefer the entry whose metric matches; fall back to the first.
        $values = null;
        foreach ($series as $entry) {
            if (is_array($entry) && ($entry['metric'] ?? null) === $metric) {
                $values = $entry['values'] ?? null;
                break;
            }
        }
        if ($values === null && isset($series[0]['values'])) {
            $values = $series[0]['values'];
        }
        if (! is_array($values)) {
            return [];
        }

        $points = [];
        foreach ($values as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawDate = $row['dateTime'] ?? $row['date'] ?? null;
            $rawValue = $row['value'] ?? null;
            if ($rawDate === null || ! is_numeric($rawValue)) {
                continue;
            }
            $points[] = ['date' => $this->normaliseDate($rawDate), 'value' => $rawValue + 0];
        }

        return $points;
    }

    /** Normalise a Metricool timestamp (ISO "2026-05-29T12:00:00+0200", or "20260515") → YYYY-MM-DD. */
    private function normaliseDate(mixed $raw): string
    {
        $s = trim((string) $raw);
        if (preg_match('/^\d{8}$/', $s)) {
            return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
        }
        // ISO date/datetime — keep the date portion.
        return substr($s, 0, 10);
    }

    /**
     * GET /actions/normalize/image/url — Metricool requires media URLs to be
     * normalised (re-hosted) before scheduling, exactly like Blotato's
     * /v2/media. Returns the normalised reference (a hosted URL string).
     *
     * CONTRACT (verified live against prod 2026-06-01):
     *   - This single endpoint normalises BOTH images AND videos. There is NO
     *     separate /actions/normalize/video/url route (it 404s). The "image" in
     *     the path is a misnomer — it is the universal media-normalise endpoint.
     *   - The response is `text/plain` whose body IS the normalised URL. It is
     *     NOT JSON: $response->json() returns null. So we read the raw body and
     *     surface it as a string in `body`.
     *   - Sending `Accept: application/json` makes the server return HTTP 406
     *     Not Acceptable (content negotiation) — that was the publish-killer
     *     bug. We use plainClient() (Accept: * / *) here.
     *
     * @return array{found:bool, status:int, body:string|null}
     */
    public function normalizeMedia(string $url): array
    {
        $query = array_merge($this->baseQuery(), ['url' => $url]);
        $response = $this->plainClient()->get('/actions/normalize/image/url', $query);

        if (! $response->successful()) {
            return ['found' => false, 'status' => $response->status(), 'body' => null];
        }

        // text/plain body = the normalised URL. Trim stray whitespace/newlines.
        $normalised = trim((string) $response->body());
        if ($normalised === '') {
            // 2xx but empty body — treat as a failure rather than publish with
            // no media (the Truthfulness/no-half-posts contract).
            return ['found' => false, 'status' => $response->status(), 'body' => null];
        }

        return ['found' => true, 'status' => $response->status(), 'body' => $normalised];
    }

    /**
     * POST /v2/scheduler/posts — schedule or publish a post.
     *
     * Body shape (verified against Metricool API docs 2026-05-30):
     *   {
     *     "providers": [{"network":"linkedin"}, …],   // OBJECTS, not strings
     *     "text": "...",
     *     "publicationDate": {"dateTime":"2026-06-01T10:00:00","timezone":"Asia/Kuala_Lumpur"},
     *     "autoPublish": true,                          // false = draft in planner
     *     "media": [ "<normalised-url-or-id>", … ],     // up to 10 (carousel/video)
     *     "<network>Data": { … }                        // optional per-network options
     *   }
     *
     * `$dryRun=true` builds + returns the body WITHOUT posting — the publish
     * probe uses this so we never create a real post during the audit.
     *
     * @param  array<int,string>          $networks
     * @param  array<int,string>          $media
     * @param  array<string,mixed>        $perNetworkData
     * @return array{dry_run:bool, body:array<string,mixed>, status?:int, response?:mixed}
     */
    public function schedulePost(
        int $blogId,
        array $networks,
        string $text,
        string $publicationDateTime,
        string $timezone,
        array $media = [],
        bool $autoPublish = true,
        array $perNetworkData = [],
        bool $dryRun = false,
    ): array {
        $body = array_merge([
            'providers' => array_map(fn (string $n) => ['network' => $n], $networks),
            'text' => $text,
            'publicationDate' => [
                'dateTime' => $publicationDateTime,
                'timezone' => $timezone,
            ],
            'autoPublish' => $autoPublish,
            'media' => array_values($media),
        ], $perNetworkData);

        if ($dryRun) {
            return ['dry_run' => true, 'body' => $body];
        }

        $response = $this->client()->post(
            '/v2/scheduler/posts?' . http_build_query($this->baseQuery($blogId)),
            $body,
        );
        $this->throwIfError($response, 'schedulePost');

        return [
            'dry_run' => false,
            'body' => $body,
            'status' => $response->status(),
            'response' => $response->json(),
        ];
    }

    /** Connectivity smoke test — true if the token + userId are valid. */
    public function ping(): bool
    {
        try {
            $this->listBrands();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function throwIfError(Response $response, string $op): void
    {
        if ($response->successful()) {
            return;
        }
        throw new RuntimeException(sprintf(
            'Metricool %s failed: HTTP %d — %s',
            $op,
            $response->status(),
            substr($response->body(), 0, 500),
        ));
    }
}
