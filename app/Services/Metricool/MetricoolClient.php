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
     * GET /stats/timeline/{metric} — ACCOUNT-LEVEL timeseries for one metric
     * over a date window. This is the data behind Metricool's "Account" growth
     * view (followers over time, impressions over time, per network) — distinct
     * from the per-post analytics in postAnalytics().
     *
     * Verified against Metricool's Swagger (app.metricool.com/api/swagger.json,
     * 2026-05-31):
     *   - ONE metric per call (the metric is the path segment, not a list).
     *   - Window params are `start` + `end` in compact `YYYYMMDD` form (NOT the
     *     `from`/`to` ISO-datetime the /v2/analytics/posts endpoints want — the
     *     two analytics families use different param conventions).
     *   - Response is an array of [timestamp, value] string pairs.
     *   - userId + blogId still required as query params (multi-tenant scoping).
     *
     * Metric identifiers used by SMT (per network) — only the ones with a real
     * Metricool timeseries; TikTok and Threads have NO timeline metric, so the
     * caller renders those as "no API data" rather than fabricating a series:
     *   instagram → igFollowers / igimpressions
     *   facebook  → fbFollows   / pageImpressions
     *   linkedin  → inFollowers / inCompanyImpressions
     *   twitter   → twitterFollowers   (no impressions timeline)
     *   youtube   → ytsubscribers / ytviews
     *
     * Returns a discriminated result so callers never have to guess shape:
     *   ['found'=>true,  'points'=>[['date'=>'YYYY-MM-DD','value'=>int|float], …]]
     *   ['found'=>false, 'status'=>int]   (404 = metric/network not available)
     *
     * @return array{found:bool, status:int, points:array<int,array{date:string,value:int|float}>}
     */
    public function getAccountTimeline(int $blogId, string $metric, string $startYmd, string $endYmd): array
    {
        $query = array_merge($this->baseQuery($blogId), [
            'start' => $startYmd,
            'end' => $endYmd,
        ]);

        $response = $this->client()->get('/stats/timeline/' . urlencode($metric), $query);

        if ($response->status() === 404) {
            return ['found' => false, 'status' => 404, 'points' => []];
        }
        $this->throwIfError($response, "getAccountTimeline({$metric})");

        return [
            'found' => true,
            'status' => $response->status(),
            'points' => $this->parseTimelinePoints($response->json()),
        ];
    }

    /**
     * Coerce Metricool's loosely-typed timeline body into [{date,value}] points.
     * The documented shape is an array of [timestamp, value] string pairs, but
     * Metricool has historically also returned {values:[…]} / {data:[…]} and
     * objects with date/value keys — accept all of them rather than break on a
     * version drift. Non-numeric values are dropped (never coerced to 0, per the
     * Truthfulness Contract: a missing reading is missing, not zero).
     *
     * @return array<int,array{date:string,value:int|float}>
     */
    private function parseTimelinePoints(mixed $body): array
    {
        // Unwrap a common envelope if present.
        if (is_array($body) && ! array_is_list($body)) {
            foreach (['values', 'data', 'timeline', 'points', 'series'] as $key) {
                if (isset($body[$key]) && is_array($body[$key])) {
                    $body = $body[$key];
                    break;
                }
            }
        }

        if (! is_array($body)) {
            return [];
        }

        $points = [];
        foreach ($body as $row) {
            [$rawDate, $rawValue] = $this->extractDateValue($row);
            if ($rawDate === null || ! is_numeric($rawValue)) {
                continue;
            }
            $value = $rawValue + 0; // int|float, preserving type
            $points[] = ['date' => $this->normaliseDate($rawDate), 'value' => $value];
        }

        return $points;
    }

    /**
     * Pull (date, value) out of one timeline row, whether it arrived as a
     * positional [ts, value] pair or as an object with named keys.
     *
     * @return array{0:mixed,1:mixed}
     */
    private function extractDateValue(mixed $row): array
    {
        if (is_array($row) && array_is_list($row)) {
            return [$row[0] ?? null, $row[1] ?? null];
        }
        if (is_array($row)) {
            $date = $row['dateTime'] ?? $row['date'] ?? $row['timestamp'] ?? $row['x'] ?? null;
            $value = $row['value'] ?? $row['count'] ?? $row['y'] ?? null;
            return [$date, $value];
        }
        return [null, null];
    }

    /** Normalise a Metricool timestamp ("20260515", "2026-05-15", ISO) → YYYY-MM-DD. */
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
     * /v2/media. Returns the normalised reference (mediaId or hosted URL).
     * Used by the publish probe's dry-run to prove the media path works.
     *
     * @return array{found:bool, status:int, body:mixed}
     */
    public function normalizeMedia(string $url): array
    {
        $query = array_merge($this->baseQuery(), ['url' => $url]);
        $response = $this->client()->get('/actions/normalize/image/url', $query);

        if (! $response->successful()) {
            return ['found' => false, 'status' => $response->status(), 'body' => $response->json()];
        }
        return ['found' => true, 'status' => $response->status(), 'body' => $response->json()];
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
