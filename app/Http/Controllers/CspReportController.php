<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receive CSP violation reports from browsers and write them to the `csp`
 * log channel. Used to validate the report-only policy before flipping CSP
 * to enforcing — the operator tails `storage/logs/csp.log`, sees what real
 * browsers actually flag, fixes the policy until the file stops growing,
 * then enables enforcement.
 *
 * Two payload shapes arrive in the wild:
 *
 *   1. Legacy `application/csp-report` (still the default for Chrome's
 *      `report-uri` directive):
 *
 *      { "csp-report": { "document-uri": "...", "violated-directive": "...",
 *                        "blocked-uri": "...", "line-number": 42, ... } }
 *
 *   2. Modern Reporting API `application/reports+json` (when the browser
 *      uses `report-to` + a `Report-To` header):
 *
 *      [{ "type": "csp-violation", "url": "...", "body": { ... } }, ...]
 *
 * We accept both, normalize to a flat shape, and emit a single log line
 * per violation. The endpoint is CSRF-exempt (the browser doesn't send our
 * token) and rate-limited (a misconfigured page could fire dozens per load).
 *
 * Security notes:
 *  - Body is bounded by Laravel's default request size cap (8MB) and we
 *    additionally cap each logged field to keep log lines bounded.
 *  - We never echo the report back, never act on its contents — it's
 *    purely write-once observability.
 *  - `blocked-uri` and `document-uri` may contain sensitive query-string
 *    fragments (auth tokens in deep links). Strip query/fragment before
 *    logging.
 */
class CspReportController extends Controller
{
    /** Maximum length per logged field — prevents an attacker from blowing up logs. */
    private const MAX_FIELD_LENGTH = 1024;

    public function store(Request $request): Response
    {
        $contentType = (string) $request->header('Content-Type', '');

        try {
            $reports = $this->normalize($request, $contentType);
        } catch (\Throwable $e) {
            // Malformed body — accept-and-drop. Don't 4xx the browser; it
            // will keep retrying and we don't want a flood. Log once per
            // unique IP via the rate limiter on the route.
            Log::channel('csp')->info('csp.report.malformed', [
                'content_type' => substr($contentType, 0, 80),
                'parse_error'  => substr($e->getMessage(), 0, 200),
                'ua'           => substr((string) $request->userAgent(), 0, 200),
            ]);
            return response('', 204);
        }

        foreach ($reports as $report) {
            Log::channel('csp')->info('csp.report', $report);
        }

        // 204 No Content is the recommended response — the browser doesn't
        // need a body and any body just wastes bandwidth on every violation.
        return response('', 204);
    }

    /**
     * Normalize either payload shape into an array of flat log records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize(Request $request, string $contentType): array
    {
        $records = [];

        if (str_starts_with($contentType, 'application/reports+json')) {
            // Reporting API: an array of report envelopes.
            $payload = $request->json()->all();
            if (! is_array($payload)) {
                throw new \RuntimeException('expected JSON array');
            }
            foreach ($payload as $envelope) {
                if (! is_array($envelope)) continue;
                if (($envelope['type'] ?? null) !== 'csp-violation') continue;
                $body = is_array($envelope['body'] ?? null) ? $envelope['body'] : [];
                $records[] = $this->flatten([
                    'document-uri'       => $envelope['url'] ?? null,
                    'referrer'           => $body['referrer'] ?? null,
                    'violated-directive' => $body['effectiveDirective'] ?? ($body['violatedDirective'] ?? null),
                    'effective-directive'=> $body['effectiveDirective'] ?? null,
                    'blocked-uri'        => $body['blockedURL'] ?? null,
                    'source-file'        => $body['sourceFile'] ?? null,
                    'line-number'        => $body['lineNumber'] ?? null,
                    'column-number'      => $body['columnNumber'] ?? null,
                    'sample'             => $body['sample'] ?? null,
                    'disposition'        => $body['disposition'] ?? null,
                    'status-code'        => $body['statusCode'] ?? null,
                ], $request);
            }
            return $records;
        }

        // Default: legacy `application/csp-report` (or text/plain if the
        // browser doesn't set Content-Type quite right).
        $body = $request->getContent();
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            // Body present but unparseable, or actively wrong shape — treat
            // as malformed so the caller logs once and moves on.
            throw new \RuntimeException('expected JSON object');
        }

        $report = is_array($payload['csp-report'] ?? null)
            ? $payload['csp-report']
            : $payload;

        $records[] = $this->flatten([
            'document-uri'        => $report['document-uri'] ?? null,
            'referrer'            => $report['referrer'] ?? null,
            'violated-directive'  => $report['violated-directive'] ?? null,
            'effective-directive' => $report['effective-directive'] ?? null,
            'blocked-uri'         => $report['blocked-uri'] ?? null,
            'source-file'         => $report['source-file'] ?? null,
            'line-number'         => $report['line-number'] ?? null,
            'column-number'       => $report['column-number'] ?? null,
            'script-sample'       => $report['script-sample'] ?? null,
            'disposition'         => $report['disposition'] ?? null,
            'status-code'         => $report['status-code'] ?? null,
        ], $request);

        return $records;
    }

    /**
     * Cap field lengths and strip query/fragment from URI fields so the log
     * doesn't accidentally capture session tokens / API keys that ride in
     * URLs. Keep the path so we can still tell which page was affected.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function flatten(array $raw, Request $request): array
    {
        $uriFields = ['document-uri', 'referrer', 'blocked-uri', 'source-file'];

        foreach ($raw as $key => $value) {
            if ($value === null || $value === '') {
                unset($raw[$key]);
                continue;
            }
            if (is_string($value) && in_array($key, $uriFields, true)) {
                $value = $this->stripQuery($value);
            }
            if (is_string($value) && strlen($value) > self::MAX_FIELD_LENGTH) {
                $value = substr($value, 0, self::MAX_FIELD_LENGTH).'…';
            }
            $raw[$key] = $value;
        }

        $raw['ua'] = substr((string) $request->userAgent(), 0, 200);
        $raw['ip'] = $request->ip();

        return $raw;
    }

    private function stripQuery(string $uri): string
    {
        $hash = strpos($uri, '#');
        if ($hash !== false) {
            $uri = substr($uri, 0, $hash);
        }
        $q = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }
        return $uri;
    }
}
