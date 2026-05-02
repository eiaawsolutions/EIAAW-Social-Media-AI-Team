<?php

namespace App\Console\Commands;

use App\Agents\StrategistAgent;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Diagnostic for the Stage 06 Run Strategist failure. Loads the brand and
 * runs StrategistAgent in-process so any thrown exception lands in stdout
 * instead of Filament's 'Agent crashed' toast.
 */
class DebugStrategist extends Command
{
    protected $signature = 'debug:strategist {brand?}';
    protected $description = 'Run StrategistAgent inline to surface the real exception.';

    public function handle(): int
    {
        $brandId = (int) ($this->argument('brand') ?: 1);
        $brand = Brand::find($brandId);
        if (! $brand) {
            $this->error("No brand with id={$brandId}");
            return self::FAILURE;
        }

        $this->info("Brand #{$brand->id} {$brand->slug}");

        try {
            $result = app(StrategistAgent::class)->run($brand);
            $this->line('ok=' . ($result->ok ? 'Y' : 'N'));
            $this->line('error=' . ($result->errorMessage ?? '-'));
            $this->line('data=' . json_encode($result->data));
        } catch (\Throwable $e) {
            $this->error(get_class($e) . ': ' . $e->getMessage());
            $this->line('  in ' . $e->getFile() . ':' . $e->getLine());
            foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 10) as $l) {
                $this->line('  ' . $l);
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
