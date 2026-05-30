<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RailwayCostClient — pulls THIS project's live infra cost from Railway's
 * GraphQL billing API (backboard.railway.com/graphql/v2) and converts the
 * resource-usage quantities into USD.
 *
 * Why convert: the `usage` / `estimatedUsage` queries return rows of
 * {measurement, value} in RESOURCE units (vCPU-minutes, GB-months, GB egress),
 * not dollars — verified against the live schema 2026-05-30. Railway's own
 * dashboard multiplies those by its published unit prices to get the $ figure
 * you see on the Usage page. We do the same, with the prices in config so a
 * Railway repricing is a one-line edit.
 *
 * Auth: needs an Account or Workspace token (usage data is workspace-scoped; a
 * project token cannot read it). The token is resolved from an Infisical handle
 * (EIAAW deploy contract) — this class only ever sees the resolved value via
 * config, never a raw secret in code.
 *
 * Failure is non-fatal by design: any missing config, auth error, network
 * error, or unexpected shape returns null. The CostMonitor treats null as
 * "fall back to the operator-set COST_RAILWAY_USD line" so the page never
 * breaks on a Railway hiccup. The truthfulness contract is preserved — we only
 * report a Railway number when the API actually returned one.
 *
 * @phpstan-type RailwayCost array{current_usd: float, estimated_usd: float, source: 'railway-api'}
 */
class RailwayCostClient
{
    /**
     * Current-cycle + estimated end-of-cycle cost for the configured project,
     * in USD. Cached for config('costs.railway.cache_ttl') seconds. Returns
     * null on any failure (caller falls back to the operator-set line).
     *
     * @return array{current_usd: float, estimated_usd: float, source: string}|null
     */
    public function cost(): ?array
    {
        if (! config('costs.railway.enabled')) {
            return null;
        }

        $token = (string) config('costs.railway.token', '');
        $projectId = (string) config('costs.railway.project_id', '');

        // A handle that never resolved (still literally "secret://...") is not
        // a usable token — treat as unconfigured rather than send it upstream.
        if ($token === '' || $projectId === '' || str_starts_with($token, 'secret://')) {
            return null;
        }

        $ttl = (int) config('costs.railway.cache_ttl', 3600);

        return Cache::remember("railway_cost:{$projectId}", $ttl, function () use ($token, $projectId) {
            return $this->fetch($token, $projectId);
        });
    }

    /**
     * One round-trip: query both `usage` (current cycle so far) and
     * `estimatedUsage` (projected end-of-cycle) for the project, then price
     * each measurement set. Cycle window = the 1st of the current month to now
     * (Railway bills on a monthly anchor; the dashboard's "current usage" is
     * the cycle-to-date — close enough for the monitor, and the estimate is the
     * authoritative end-of-cycle figure regardless).
     *
     * @return array{current_usd: float, estimated_usd: float, source: string}|null
     */
    private function fetch(string $token, string $projectId): ?array
    {
        $measurements = ['CPU_USAGE', 'MEMORY_USAGE_GB', 'DISK_USAGE_GB', 'NETWORK_TX_GB', 'BACKUP_USAGE_GB'];

        // estimatedUsage is the ROBUST query: no date args (those are where a
        // type mismatch caused HTTP 400), it's what powers the dashboard's
        // "Estimated" figure, and it's the authoritative end-of-cycle number.
        // We make this the primary call so a single bad query can't sink the
        // whole feature.
        $estimated = $this->queryEstimated($token, $projectId, $measurements);
        if ($estimated === null) {
            return null;
        }

        // usage (cycle-to-date) is a best-effort SECONDARY. If its date-arg
        // typing trips a 400, we degrade to showing the estimate for both
        // figures rather than failing the whole line.
        $current = $this->queryCurrent($token, $projectId, $measurements);

        return [
            'current_usd' => round($current ?? $estimated, 2),
            'estimated_usd' => round($estimated, 2),
            'source' => 'railway-api',
        ];
    }

    /**
     * estimatedUsage(projectId, measurements) → priced USD, or null on failure.
     * No date arguments, so the request shape is minimal and stable.
     */
    private function queryEstimated(string $token, string $projectId, array $measurements): ?float
    {
        $query = <<<'GQL'
        query Est($projectId: String!, $measurements: [MetricMeasurement!]!) {
          estimatedUsage(projectId: $projectId, measurements: $measurements) {
            measurement
            estimatedValue
          }
        }
        GQL;

        $data = $this->gql($token, $query, [
            'projectId' => $projectId,
            'measurements' => $measurements,
        ]);

        if ($data === null) {
            return null;
        }

        return $this->priceRows($data['estimatedUsage'] ?? [], 'estimatedValue');
    }

    /**
     * usage(projectId, measurements, startDate, endDate) → priced USD, or null.
     * Best-effort: callers treat null as "fall back to the estimate". Railway
     * types the date args as DateTime scalars; we pass ISO-8601 which Railway
     * accepts for DateTime, and declare the GraphQL var type as DateTime to
     * match the schema (a String! declaration is what produced the 400).
     */
    private function queryCurrent(string $token, string $projectId, array $measurements): ?float
    {
        $query = <<<'GQL'
        query Cur($projectId: String!, $measurements: [MetricMeasurement!]!, $startDate: DateTime!, $endDate: DateTime!) {
          usage(projectId: $projectId, measurements: $measurements, startDate: $startDate, endDate: $endDate) {
            measurement
            value
          }
        }
        GQL;

        $data = $this->gql($token, $query, [
            'projectId' => $projectId,
            'measurements' => $measurements,
            'startDate' => now()->startOfMonth()->toIso8601String(),
            'endDate' => now()->toIso8601String(),
        ]);

        if ($data === null) {
            return null;
        }

        return $this->priceRows($data['usage'] ?? [], 'value');
    }

    /**
     * Execute a single GraphQL query and return its `data` payload, or null on
     * any failure (HTTP error, GraphQL errors, malformed body). Logs the
     * response body on failure so a query-shape problem is diagnosable from the
     * logs without leaking the token (only the request body + status are logged).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>|null
     */
    private function gql(string $token, string $query, array $variables): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ])
                ->timeout(8)
                ->retry(1, 500, throw: false)
                ->post((string) config('costs.railway.endpoint'), [
                    'query' => $query,
                    'variables' => $variables,
                ]);

            if ($response->failed()) {
                Log::warning('RailwayCostClient: HTTP failure', [
                    'status' => $response->status(),
                    // Body carries Railway's validation message; no token in it.
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $json = $response->json();

            if (! is_array($json) || isset($json['errors']) || ! isset($json['data'])) {
                Log::warning('RailwayCostClient: GraphQL errors', [
                    'errors' => $json['errors'] ?? 'malformed',
                ]);

                return null;
            }

            return $json['data'];
        } catch (\Throwable $e) {
            Log::warning('RailwayCostClient: exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Convert a set of {measurement, <valueKey>} rows into USD using the
     * configured unit prices. Returns null if the rows are empty/unusable so
     * the caller can distinguish "no data" from "genuinely $0".
     *
     * @param  array<int, array{measurement?: string, value?: float, estimatedValue?: float}>  $rows
     */
    private function priceRows(array $rows, string $valueKey): ?float
    {
        if ($rows === []) {
            return null;
        }

        $prices = (array) config('costs.railway.unit_prices_usd', []);

        // UNIT BASIS (verified live 2026-05-30): Railway returns the time-based
        // measurements in RESOURCE-MINUTES, so CPU/memory/disk/backup all price
        // per-minute. Egress (NETWORK_TX_GB) is a GB volume, priced per-GB.
        // Pricing disk/backup per GB-MONTH here was the ~30x overstatement bug.
        $priceFor = static fn (string $m): float => match ($m) {
            'CPU_USAGE' => (float) ($prices['cpu_vcpu_minute'] ?? 0),
            'MEMORY_USAGE_GB' => (float) ($prices['memory_gb_minute'] ?? 0),
            'DISK_USAGE_GB' => (float) ($prices['disk_gb_minute'] ?? 0),
            'NETWORK_TX_GB' => (float) ($prices['network_tx_gb'] ?? 0),
            'BACKUP_USAGE_GB' => (float) ($prices['backup_gb_minute'] ?? 0),
            default => 0.0,
        };

        $total = 0.0;
        $sawRow = false;
        $debug = [];

        foreach ($rows as $row) {
            if (! isset($row['measurement'])) {
                continue;
            }
            $qty = (float) ($row[$valueKey] ?? 0);
            $contribution = $qty * $priceFor((string) $row['measurement']);
            $total += $contribution;
            $sawRow = true;
            $debug[(string) $row['measurement']] = ['qty' => $qty, 'usd' => round($contribution, 4)];
        }

        // TEMP DIAGNOSTIC (gated): logs raw Railway quantities + priced USD so
        // the unit-price conversion can be reconciled against the dashboard.
        // Remove once the unit prices are confirmed correct.
        if (config('costs.railway.debug')) {
            Log::info('RailwayCostClient: priced rows', [
                'value_key' => $valueKey,
                'rows' => $debug,
                'total_usd' => round($total, 4),
            ]);
        }

        return $sawRow ? $total : null;
    }
}
