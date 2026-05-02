<?php

namespace App\Console\Commands;

use App\Agents\OnboardingAgent;
use App\Models\Brand;
use App\Models\BrandStyle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic for the Stage 02 "Run Onboarding agent" failure. Loads the
 * brand the user clicked from, dumps relevant DB state, and re-runs the
 * agent in-process so any thrown exception lands in stdout instead of a
 * Filament toast that swallows the message.
 */
class DebugStage2 extends Command
{
    protected $signature = 'debug:stage2 {brand?}';
    protected $description = 'Inspect + re-run OnboardingAgent for a brand to surface the real exception.';

    public function handle(): int
    {
        $brandId = $this->argument('brand');
        $brand = $brandId
            ? Brand::find((int) $brandId)
            : Brand::orderBy('id')->first();

        if (! $brand) {
            $this->error('No brand to inspect.');
            return self::FAILURE;
        }

        $this->info("=== Brand #{$brand->id} {$brand->slug} ===");
        $this->line('name: ' . $brand->name);
        $this->line('website_url: ' . ($brand->website_url ?: '(empty)'));
        $this->line('industry: ' . ($brand->industry ?: '(empty)'));
        $this->line('workspace_id: ' . $brand->workspace_id);

        $this->newLine();
        $this->info('=== brand_styles columns ===');
        $this->line(implode(', ', Schema::getColumnListing('brand_styles')));

        $this->newLine();
        $this->info('=== existing brand_styles for this brand ===');
        $rows = BrandStyle::where('brand_id', $brand->id)->orderBy('version')->get();
        $this->line('count: ' . $rows->count());
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  v%d id=%d current=%s words=%d embedding=%s',
                $r->version,
                $r->id,
                $r->is_current ? 'Y' : 'n',
                str_word_count((string) $r->content_md),
                empty($r->embedding) ? 'null' : 'set',
            ));
        }

        $this->newLine();
        $this->info('=== running OnboardingAgent in-process ===');
        try {
            $agent = app(OnboardingAgent::class);
            $result = $agent->run($brand);
            $this->line('ok=' . ($result->ok ? 'Y' : 'N'));
            $this->line('error=' . ($result->errorMessage ?? '-'));
            $this->line('data=' . json_encode($result->data));
        } catch (\Throwable $e) {
            $this->error(get_class($e) . ': ' . $e->getMessage());
            $this->line('  in ' . $e->getFile() . ':' . $e->getLine());
            foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 12) as $l) {
                $this->line('  ' . $l);
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
