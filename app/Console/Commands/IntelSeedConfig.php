<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the per-brand intelligence configs that gate the weekly `intel:refresh`
 * pipeline. Without these, `IntelRefresh` skips the competitor stages
 * (`->whereNotNull('competitor_intel_config')`) and `MarketIntelAgent` skips
 * brands whose per-brand config is absent/disabled — so the Strategist plans on
 * brand-voice alone (the content-recycling root cause).
 *
 * What it writes (idempotent, merge-not-clobber):
 *   - market_intel_config = { "enabled": true }   — needs NO external targets, so
 *     market/trend intel lights up immediately for every seeded brand (given a
 *     resolvable FIRECRAWL_API_KEY + MARKET_INTEL_ENABLED=true).
 *   - competitor_intel_config = { "enabled": true, "handles": [...], "geo_codes": [...] }
 *     — the `handles` are operator knowledge (Meta page IDs / LinkedIn company
 *     slugs). Supply them via --competitors so competitor intel can pull; the
 *     command NEVER invents handles. When no handles are supplied it seeds
 *     { "enabled": true, "handles": [], "geo_codes": [...] } so the config exists
 *     (unblocking the IntelRefresh gate) while the raw-ad pull no-ops until real
 *     handles are added. Existing handles are preserved and merged, never wiped.
 *   - growth_strategy_config = { "enabled": true } — growth intel needs no external
 *     fetch (runs off Metricool metrics already wired), so it's safe to enable.
 *
 * Examples:
 *   php artisan intel:seed-config --brand=1 --dry-run
 *   php artisan intel:seed-config --brand=1 \
 *     --competitors='[{"platform":"meta","page_id":"1234567890","label":"Acme"},
 *                     {"platform":"linkedin","company_slug":"acme","label":"Acme"}]'
 *   php artisan intel:seed-config            # all non-archived brands: market+growth on
 */
class IntelSeedConfig extends Command
{
    protected $signature = 'intel:seed-config
                            {--brand= : Limit to one brand_id (default = all non-archived brands)}
                            {--competitors= : JSON array of competitor handles to MERGE into competitor_intel_config (only valid with --brand)}
                            {--geo=MY,SG : Comma-separated ISO country codes for the Meta Ad Library geo filter}
                            {--no-market : Do NOT enable market_intel_config (leave as-is)}
                            {--no-growth : Do NOT enable growth_strategy_config (leave as-is)}
                            {--dry-run : Report the planned config changes without writing}';

    protected $description = 'Seed per-brand competitor/market/growth intel configs so intel:refresh actually runs.';

    public function handle(): int
    {
        $brandId = (int) $this->option('brand');
        $dry = (bool) $this->option('dry-run');

        // Parse + validate competitor handles up front so we fail before touching
        // the DB. Only meaningful for a single brand — handles are per-brand.
        $handles = [];
        if ($raw = $this->option('competitors')) {
            if (! $brandId) {
                $this->error('--competitors requires --brand (handles are per-brand).');

                return self::FAILURE;
            }
            $handles = $this->parseHandles($raw);
            if ($handles === null) {
                return self::FAILURE; // parseHandles already printed the reason
            }
        }

        $geoCodes = $this->parseGeo((string) $this->option('geo'));

        $query = Brand::query()->whereNull('archived_at')->orderBy('id');
        if ($brandId) {
            $query->where('id', $brandId);
        }
        $brands = $query->get();

        if ($brands->isEmpty()) {
            $this->warn('No matching brands.');

            return self::SUCCESS;
        }

        $this->info(sprintf('intel:seed-config — %d brand(s)%s', $brands->count(), $dry ? ' [DRY RUN]' : ''));

        $changed = 0;
        foreach ($brands as $brand) {
            $before = [
                'competitor_intel_config' => $brand->competitor_intel_config,
                'market_intel_config' => $brand->market_intel_config,
                'growth_strategy_config' => $brand->growth_strategy_config,
            ];

            $competitorCfg = $this->mergeCompetitorConfig(
                (array) ($brand->competitor_intel_config ?? []),
                $handles,
                $geoCodes,
            );

            $marketCfg = $this->option('no-market')
                ? ($brand->market_intel_config ?? null)
                : $this->enableConfig((array) ($brand->market_intel_config ?? []));

            $growthCfg = $this->option('no-growth')
                ? ($brand->growth_strategy_config ?? null)
                : $this->enableConfig((array) ($brand->growth_strategy_config ?? []));

            $after = [
                'competitor_intel_config' => $competitorCfg,
                'market_intel_config' => $marketCfg,
                'growth_strategy_config' => $growthCfg,
            ];

            $handleCount = count($competitorCfg['handles'] ?? []);
            $this->line(sprintf(
                '-> brand#%d %s  competitor(enabled=%s handles=%d) market(enabled=%s) growth(enabled=%s)',
                $brand->id,
                $brand->slug,
                ($competitorCfg['enabled'] ?? false) ? 'yes' : 'no',
                $handleCount,
                (($marketCfg['enabled'] ?? false)) ? 'yes' : 'no',
                (($growthCfg['enabled'] ?? false)) ? 'yes' : 'no',
            ));

            if ($before == $after) {
                $this->line('   (no change)');

                continue;
            }

            if (! $dry) {
                // Assign only what actually changed to avoid churning updated_at
                // on untouched brands; models cast these to array on save.
                $brand->competitor_intel_config = $after['competitor_intel_config'];
                $brand->market_intel_config = $after['market_intel_config'];
                $brand->growth_strategy_config = $after['growth_strategy_config'];
                $brand->save();
            }
            $changed++;
        }

        $this->newLine();
        $this->info(sprintf('%s %d brand(s) %s.', $dry ? 'Would update' : 'Updated', $changed, $dry ? '' : 'written'));

        if ($changed > 0 && ! $dry) {
            $this->line('Next: php artisan intel:refresh'.($brandId ? " --brand={$brandId}" : '').'  (needs FIRECRAWL_API_KEY + MARKET_INTEL_ENABLED=true)');
        }

        return self::SUCCESS;
    }

    /**
     * Set enabled=true on a config array, preserving any other keys already present.
     *
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    private function enableConfig(array $cfg): array
    {
        $cfg['enabled'] = true;

        return $cfg;
    }

    /**
     * Merge new handles into an existing competitor_intel_config without wiping
     * previously configured targets. Dedupes on (platform, page_id|company_slug).
     *
     * @param  array<string,mixed>  $existing
     * @param  array<int,array<string,mixed>>  $newHandles
     * @param  array<int,string>  $geoCodes
     * @return array<string,mixed>
     */
    private function mergeCompetitorConfig(array $existing, array $newHandles, array $geoCodes): array
    {
        $existing['enabled'] = true;

        $current = array_values(array_filter(
            (array) ($existing['handles'] ?? []),
            'is_array',
        ));

        $index = [];
        foreach ($current as $h) {
            $index[$this->handleKey($h)] = true;
        }
        foreach ($newHandles as $h) {
            $key = $this->handleKey($h);
            if (! isset($index[$key])) {
                $current[] = $h;
                $index[$key] = true;
            }
        }

        $existing['handles'] = $current;

        // Only set geo_codes when not already present, or when the operator
        // passed a non-default set — never clobber an intentional prior value
        // with the default. (Default MY,SG is applied when the key is absent.)
        if (! isset($existing['geo_codes']) || empty($existing['geo_codes'])) {
            $existing['geo_codes'] = $geoCodes;
        }

        return $existing;
    }

    /** @param array<string,mixed> $h */
    private function handleKey(array $h): string
    {
        $platform = (string) ($h['platform'] ?? '');
        $id = (string) ($h['page_id'] ?? $h['company_slug'] ?? '');

        return mb_strtolower($platform.'|'.$id);
    }

    /**
     * Parse + validate the --competitors JSON. Returns null (and prints why) on
     * any malformed / unusable entry, so we never seed a broken handle.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function parseHandles(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('--competitors must be a JSON array. Got: '.mb_substr($raw, 0, 60));

            return null;
        }

        $out = [];
        foreach ($decoded as $i => $h) {
            if (! is_array($h)) {
                $this->error("--competitors[$i] is not an object.");

                return null;
            }
            $platform = $h['platform'] ?? null;
            if (! in_array($platform, ['meta', 'linkedin'], true)) {
                $this->error("--competitors[$i].platform must be 'meta' or 'linkedin'.");

                return null;
            }
            if ($platform === 'meta' && empty($h['page_id'])) {
                $this->error("--competitors[$i] (meta) requires a page_id.");

                return null;
            }
            if ($platform === 'linkedin' && empty($h['company_slug'])) {
                $this->error("--competitors[$i] (linkedin) requires a company_slug.");

                return null;
            }
            // Keep only the recognised keys, cast to string for stable storage.
            $clean = ['platform' => $platform];
            foreach (['page_id', 'company_slug', 'label'] as $k) {
                if (isset($h[$k]) && $h[$k] !== '') {
                    $clean[$k] = (string) $h[$k];
                }
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function parseGeo(string $raw): array
    {
        $codes = array_values(array_filter(array_map(
            fn ($c) => strtoupper(trim($c)),
            explode(',', $raw),
        )));

        return $codes ?: ['MY', 'SG'];
    }
}
