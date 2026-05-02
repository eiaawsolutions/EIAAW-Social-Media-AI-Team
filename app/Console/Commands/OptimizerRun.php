<?php

namespace App\Console\Commands;

use App\Agents\OptimizerAgent;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Runs the OptimizerAgent for every non-archived brand. Cron entry: weekly
 * Monday 02:00 UTC. Brands with <3 posts in the window are skipped (the
 * agent fails fast with a clear message; we log + continue so one quiet
 * brand doesn't break others).
 */
class OptimizerRun extends Command
{
    protected $signature = 'optimizer:run {--brand= : Limit to one brand_id} {--window=30 : Days back to consider}';
    protected $description = 'Run OptimizerAgent for every brand and write a fresh strategist_recommendations row.';

    public function handle(): int
    {
        $brandQuery = Brand::whereNull('archived_at')->orderBy('id');
        if ($brandId = (int) $this->option('brand')) {
            $brandQuery->where('id', $brandId);
        }
        $brands = $brandQuery->get();
        $window = (int) $this->option('window');

        $ran = 0;
        $skipped = 0;
        foreach ($brands as $brand) {
            try {
                $r = app(OptimizerAgent::class)->run($brand, ['window_days' => $window]);
            } catch (\Throwable $e) {
                $this->warn("Brand #{$brand->id}: crashed — " . $e->getMessage());
                $skipped++;
                continue;
            }
            if ($r->ok) {
                $ran++;
                $this->info("Brand #{$brand->id} {$brand->slug}: ok ({$r->data['post_count']} posts)");
            } else {
                $skipped++;
                $this->line("Brand #{$brand->id} {$brand->slug}: skipped — " . $r->errorMessage);
            }
        }

        $this->newLine();
        $this->info("Done: {$ran} updated, {$skipped} skipped.");
        return self::SUCCESS;
    }
}
