<?php

namespace App\Console\Commands;

use App\Models\PostMetric;
use App\Services\Metrics\MetricoolMetricsCollector;
use Illuminate\Console\Command;

/**
 * One-shot backfill for the corrupted `post_metrics.engagement_rate` column.
 *
 * Background: until 2026-07-31 MetricoolMetricsCollector::normalise() probed
 * ['interactions', 'engagement'] for the engagement NUMERATOR, on the
 * documented assumption that both are counts. A live prod audit disproved that
 * for `engagement` — it is a PERCENTAGE Metricool has already divided by the
 * post's own audience. Dividing it again by impressions stored impossible
 * rates: LinkedIn post 449 read 4.8889 (489%).
 *
 * Corrupted networks: linkedin, facebook, threads, tiktok (any payload
 * carrying an `engagement` key but no `interactions`). Instagram was correct
 * by accident (it supplies `interactions`, probed first) and YouTube was
 * correct because it has no `engagement` key — rows on those networks
 * recompute to the identical value and are reported as unchanged.
 *
 * This is safely repeatable: it recomputes from the untouched `raw_payload`
 * using the CURRENT (fixed) normaliser, so re-running converges on the same
 * answer. Only `engagement_rate` is written — every raw counter is left alone.
 *
 * Dry-run by default; pass --apply to write.
 */
class MetricsRecomputeEngagementRate extends Command
{
    protected $signature = 'metrics:recompute-engagement-rate
                            {--apply : Write the corrected values (default is a dry run)}
                            {--chunk=500 : Rows per batch}';

    protected $description = 'Recompute post_metrics.engagement_rate from raw_payload after the Metricool `engagement`-is-a-rate fix.';

    public function handle(): int
    {
        // Constructed directly, not injected: the collector's only constructor
        // arg is a nullable MetricoolClient the container cannot resolve, and
        // this backfill never calls the API — it re-runs the pure normalise()
        // over raw_payload we already hold.
        $collector = new MetricoolMetricsCollector(null);

        $apply = (bool) $this->option('apply');
        $scanned = 0;
        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $worst = ['id' => null, 'from' => 0.0, 'to' => 0.0];

        $this->info($apply ? 'APPLYING corrected engagement rates…' : 'DRY RUN — no writes. Pass --apply to persist.');

        PostMetric::query()
            ->where('source', 'metricool')
            ->whereNotNull('raw_payload')
            // chunkById owns its own ordering — never add an orderBy here or the
            // id > ? cursor is corrupted and rows get re-scanned.
            ->chunkById((int) $this->option('chunk'), function ($rows) use (
                $collector, $apply, &$scanned, &$changed, &$unchanged, &$skipped, &$worst
            ): void {
                foreach ($rows as $row) {
                    $scanned++;
                    $raw = $row->raw_payload;
                    if (! is_array($raw) || $raw === []) {
                        $skipped++;
                        continue;
                    }

                    $fresh = $collector->normalise((string) $row->platform, $raw);
                    $new = $fresh['engagement_rate'];
                    $old = $row->engagement_rate === null ? null : (float) $row->engagement_rate;

                    // Compare at the stored precision (decimal:4) so float noise
                    // isn't mistaken for a real correction.
                    $same = ($old === null && $new === null)
                        || ($old !== null && $new !== null && abs($old - $new) < 0.00005);

                    if ($same) {
                        $unchanged++;
                        continue;
                    }

                    if ($old !== null && $new !== null && ($old - $new) > ($worst['from'] - $worst['to'])) {
                        $worst = ['id' => $row->id, 'from' => $old, 'to' => $new];
                    }

                    $changed++;
                    if ($apply) {
                        // Only the derived column — raw counters are untouched.
                        $row->forceFill(['engagement_rate' => $new])->saveQuietly();
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['scanned', 'corrected', 'already correct', 'skipped (no payload)'],
            [[$scanned, $changed, $unchanged, $skipped]],
        );

        if ($worst['id'] !== null) {
            $this->line(sprintf(
                '  largest correction: post_metrics id=%d  %.4f → %.4f',
                $worst['id'], $worst['from'], $worst['to'],
            ));
        }

        if (! $apply && $changed > 0) {
            $this->warn("Dry run: {$changed} rows would change. Re-run with --apply to persist.");
        }

        return self::SUCCESS;
    }
}
