<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic for the BrandCorpusSeed page actions. Reproduces the
 * scrape + embed + insert flow in CLI so any thrown exception lands in
 * stdout instead of Filament's generic 'Error while loading page' toast.
 */
class DebugCorpus extends Command
{
    protected $signature = 'debug:corpus {brand?}';
    protected $description = 'Run scrape+embed+insert for a brand to surface the real exception.';

    public function handle(): int
    {
        $brandId = (int) ($this->argument('brand') ?: 1);
        $brand = Brand::find($brandId);
        if (! $brand) {
            $this->error("No brand with id={$brandId}");
            return self::FAILURE;
        }

        $this->info("Brand #{$brand->id} {$brand->slug}");
        $this->line('website_url: ' . ($brand->website_url ?: '(empty)'));
        $this->line('existing brand_corpus rows: ' . BrandCorpusItem::where('brand_id', $brand->id)->count());

        // Replicate BrandCorpusSeed::scrapeWebsiteChunks logic to see what we get.
        $url = (string) $brand->website_url;
        if ($url === '') {
            $this->error('No website_url; aborting.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== scraping ===');
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30, 'allow_redirects' => true]);
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'EIAAW-SocialMediaTeam/1.0 (+https://eiaawsolutions.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->error('scrape FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            return self::FAILURE;
        }

        $html = (string) $response->getBody();
        $this->line('html length: ' . strlen($html));

        $cleaned = preg_replace(
            ['/<script\b[^>]*>.*?<\/script>/is', '/<style\b[^>]*>.*?<\/style>/is', '/<nav\b[^>]*>.*?<\/nav>/is', '/<footer\b[^>]*>.*?<\/footer>/is'],
            ' ',
            $html
        );
        $text = trim(html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{2,}/', "\n\n", $text);
        $this->line('cleaned text length: ' . mb_strlen($text));

        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
        $chunks = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 80) continue;
            if (mb_strlen($p) > 2000) {
                foreach (str_split($p, 2000) as $sub) {
                    $chunks[] = trim($sub);
                }
            } else {
                $chunks[] = $p;
            }
        }
        $chunks = array_values(array_unique(array_filter($chunks)));
        $this->line('chunk count: ' . count($chunks));
        foreach (array_slice($chunks, 0, 3) as $i => $c) {
            $this->line(sprintf('  [%d] (%d chars): %s', $i, mb_strlen($c), substr($c, 0, 80) . '…'));
        }

        if (empty($chunks)) {
            $this->error('No usable chunks; aborting before embed.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== embedding (Voyage) ===');
        try {
            $vectors = app(EmbeddingService::class)->embedMany(
                texts: $chunks,
                brand: $brand,
                workspace: $brand->workspace,
            );
            $this->line('vectors returned: ' . count($vectors));
            $this->line('first vector class: ' . (isset($vectors[0]) ? get_class($vectors[0]) : 'NONE'));
        } catch (\Throwable $e) {
            $this->error('embed FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            $this->line('  in ' . $e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== inserting brand_corpus rows ===');
        try {
            DB::transaction(function () use ($brand, $chunks, $vectors): void {
                foreach ($chunks as $i => $text) {
                    BrandCorpusItem::create([
                        'brand_id' => $brand->id,
                        'source_type' => 'website_page',
                        'source_url' => $brand->website_url,
                        'source_label' => 'Website chunk ' . ($i + 1),
                        'content' => $text,
                        'embedding' => $vectors[$i],
                    ]);
                }
            });
        } catch (\Throwable $e) {
            $this->error('insert FAILED: ' . get_class($e) . ': ' . $e->getMessage());
            $this->line('  in ' . $e->getFile() . ':' . $e->getLine());
            foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 8) as $l) {
                $this->line('  ' . $l);
            }
            return self::FAILURE;
        }

        $this->info('OK — total brand_corpus rows now: ' . BrandCorpusItem::where('brand_id', $brand->id)->count());
        return self::SUCCESS;
    }
}
