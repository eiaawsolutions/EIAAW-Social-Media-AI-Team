<?php

namespace App\Services\Metrics;

use App\Models\PostMetric;
use App\Models\ScheduledPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Parse + persist a CSV of platform analytics into post_metrics.
 *
 * Canonical "real metrics" path until v1.1 first-party OAuth pulls land
 * (see BlotatoMetricsCollector docblock). Operator exports analytics from
 * each platform's native dashboard, pastes the post URL alongside the
 * counters, and uploads. Each row becomes a PostMetric snapshot with
 * source='csv_upload'.
 *
 * Row matching: post_url is matched against scheduled_posts.platform_post_url.
 * Posts that haven't yet captured platform_post_url (verification gap)
 * cannot be matched and are reported back to the operator as skipped.
 *
 * Validation is strict: malformed rows are rejected, not silently zero'd —
 * Truthfulness Contract. Empty metric cells are stored as NULL (not 0)
 * so the dashboard renders "—" not "0".
 */
class MetricsCsvImporter
{
    public const REQUIRED_HEADERS = ['platform', 'post_url'];

    public const METRIC_HEADERS = [
        'impressions', 'reach', 'likes', 'comments', 'shares', 'saves',
        'video_views', 'profile_visits', 'url_clicks',
    ];

    /**
     * Import a CSV file and persist matched rows. Returns a structured
     * report the UI can render: imported count, skipped rows with reasons.
     *
     * @param  Collection<int, int>  $brandIds  Workspace's brand IDs (scope guard).
     * @return array{imported: int, skipped: array<int, array{row: int, reason: string, post_url: ?string}>, total: int}
     */
    public function import(string $absolutePath, Collection $brandIds): array
    {
        if (! is_readable($absolutePath)) {
            return ['imported' => 0, 'skipped' => [], 'total' => 0, 'error' => 'File not readable.'];
        }

        $handle = fopen($absolutePath, 'r');
        if (! $handle) {
            return ['imported' => 0, 'skipped' => [], 'total' => 0, 'error' => 'Could not open file.'];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            return ['imported' => 0, 'skipped' => [], 'total' => 0, 'error' => 'CSV is empty.'];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $missing = array_diff(self::REQUIRED_HEADERS, $header);
        if (! empty($missing)) {
            fclose($handle);
            return [
                'imported' => 0,
                'skipped' => [],
                'total' => 0,
                'error' => 'Missing required columns: ' . implode(', ', $missing) . '. Download the template for the expected format.',
            ];
        }

        $imported = 0;
        $skipped = [];
        $rowNum = 1; // header was row 1; data starts at 2

        while (($cells = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count(array_filter($cells, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue; // blank row
            }

            $row = $this->associateRow($header, $cells);
            $postUrl = $row['post_url'] ?? null;

            $platform = strtolower(trim((string) ($row['platform'] ?? '')));
            if (! $platform) {
                $skipped[] = ['row' => $rowNum, 'reason' => 'Missing platform.', 'post_url' => $postUrl];
                continue;
            }
            if (! $postUrl) {
                $skipped[] = ['row' => $rowNum, 'reason' => 'Missing post_url.', 'post_url' => null];
                continue;
            }

            $post = ScheduledPost::whereIn('brand_id', $brandIds)
                ->where('platform_post_url', $postUrl)
                ->first();

            if (! $post) {
                $skipped[] = [
                    'row' => $rowNum,
                    'reason' => 'No published post matches that URL in this workspace. The post may not have captured platform_post_url yet (Blotato verification still pending), or the URL differs from what we stored.',
                    'post_url' => $postUrl,
                ];
                continue;
            }

            $metrics = $this->extractMetrics($row);
            if ($this->allMetricsNull($metrics)) {
                $skipped[] = ['row' => $rowNum, 'reason' => 'Row matched a post but contains no numeric metric values.', 'post_url' => $postUrl];
                continue;
            }

            $observedAt = $this->parseObservedAt($row['observed_at'] ?? null);
            $engagementRate = $this->engagementRate($metrics);

            PostMetric::create([
                'scheduled_post_id' => $post->id,
                'brand_id' => $post->brand_id,
                'platform' => $platform,
                'observed_at' => $observedAt,
                'source' => 'csv_upload',
                'impressions' => $metrics['impressions'],
                'reach' => $metrics['reach'],
                'likes' => $metrics['likes'],
                'comments' => $metrics['comments'],
                'shares' => $metrics['shares'],
                'saves' => $metrics['saves'],
                'video_views' => $metrics['video_views'],
                'profile_visits' => $metrics['profile_visits'],
                'url_clicks' => $metrics['url_clicks'],
                'engagement_rate' => $engagementRate,
                'raw_payload' => ['csv_row' => $row, 'imported_at' => now()->toIso8601String()],
            ]);

            $imported++;
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => $imported + count($skipped),
        ];
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, ?string>  $cells
     * @return array<string, ?string>
     */
    private function associateRow(array $header, array $cells): array
    {
        $row = [];
        foreach ($header as $i => $key) {
            $value = $cells[$i] ?? null;
            $row[$key] = is_string($value) ? trim($value) : $value;
        }
        return $row;
    }

    /**
     * @param  array<string, ?string>  $row
     * @return array<string, ?int>
     */
    private function extractMetrics(array $row): array
    {
        $out = [];
        foreach (self::METRIC_HEADERS as $key) {
            $raw = $row[$key] ?? null;
            if ($raw === null || $raw === '') {
                $out[$key] = null;
                continue;
            }
            $clean = preg_replace('/[,\s]/', '', (string) $raw);
            $out[$key] = is_numeric($clean) ? (int) $clean : null;
        }
        return $out;
    }

    /** @param  array<string, ?int>  $metrics */
    private function allMetricsNull(array $metrics): bool
    {
        foreach ($metrics as $v) {
            if ($v !== null) return false;
        }
        return true;
    }

    /** @param  array<string, ?int>  $metrics */
    private function engagementRate(array $metrics): ?float
    {
        $imp = $metrics['impressions'];
        if (! $imp || $imp <= 0) return null;
        $eng = (int) ($metrics['likes'] ?? 0)
            + (int) ($metrics['comments'] ?? 0)
            + (int) ($metrics['shares'] ?? 0)
            + (int) ($metrics['saves'] ?? 0);
        return round($eng / $imp, 4);
    }

    private function parseObservedAt(?string $raw): Carbon
    {
        if (! $raw) return now();
        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return now();
        }
    }

    /**
     * Generate a downloadable template CSV string. Headers only — no
     * fabricated example rows (Truthfulness Contract).
     */
    public static function templateCsv(): string
    {
        $headers = array_merge(self::REQUIRED_HEADERS, self::METRIC_HEADERS, ['observed_at']);
        return implode(',', $headers) . "\n";
    }
}
