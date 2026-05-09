<?php

namespace App\Console\Commands;

use App\Agents\CompetitorIntelAgent;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Weekly cron entrypoint for competitor intel refresh.
 * Iterates every active brand that has at least one competitor handle
 * configured. Each brand runs in isolation — one brand's failure (Meta
 * rate-limit, Firecrawl timeout) doesn't block the others.
 *
 * Schedule (bootstrap/app.php): every Monday 03:00 UTC, after Optimizer
 * (02:00) so the next StrategistAgent.run picks up both signals together.
 */
class IntelRefresh extends Command
{
    protected $signature = 'intel:refresh
                            {--brand= : Run for a single brand id (default = all brands)}
                            {--limit=200 : Max brands to process}';

    protected $description = 'Refresh competitor ad intelligence for every brand with handles configured.';

    public function handle(CompetitorIntelAgent $agent): int
    {
        $brandId = $this->option('brand');
        $limit = max(1, (int) $this->option('limit'));

        $query = Brand::query()
            ->whereNull('archived_at')
            ->whereNotNull('competitor_intel_config');

        if ($brandId) {
            $query->where('id', $brandId);
        }

        $brands = $query->limit($limit)->get();

        $this->info(sprintf('intel:refresh — scanning %d brand(s)', $brands->count()));

        $totals = ['ok' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($brands as $brand) {
            $this->line(sprintf('-> brand#%d %s', $brand->id, $brand->name));
            try {
                $result = $agent->run($brand, []);
                if ($result->ok) {
                    if (! empty($result->data['skipped'])) {
                        $totals['skipped']++;
                        $this->line('   skipped: '.($result->data['reason'] ?? 'unknown'));
                    } else {
                        $totals['ok']++;
                        $this->line(sprintf(
                            '   meta=%d/%d li=%d/%d errors=%d',
                            $result->data['meta_inserted'] ?? 0,
                            $result->data['meta_fetched'] ?? 0,
                            $result->data['linkedin_inserted'] ?? 0,
                            $result->data['linkedin_fetched'] ?? 0,
                            $result->data['errors'] ?? 0,
                        ));
                    }
                } else {
                    $totals['failed']++;
                    $this->warn('   failed: '.$result->errorMessage);
                }
            } catch (\Throwable $e) {
                $totals['failed']++;
                $this->warn('   crashed: '.substr($e->getMessage(), 0, 160));
            }
        }

        $this->line('');
        $this->info(sprintf('done — ok=%d skipped=%d failed=%d', $totals['ok'], $totals['skipped'], $totals['failed']));
        return self::SUCCESS;
    }
}
