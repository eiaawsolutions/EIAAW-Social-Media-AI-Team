<?php

namespace App\Console\Commands;

use App\Agents\CompetitorIntelAgent;
use App\Agents\CompetitorStrategistAgent;
use App\Agents\GrowthStrategistAgent;
use App\Agents\MarketIntelAgent;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Weekly cron entrypoint for the per-brand Strategy Briefing. For each active
 * brand it runs, in order:
 *   1. CompetitorIntelAgent      — pull raw competitor ad creatives (Meta + LinkedIn)
 *   2. CompetitorStrategistAgent — synthesise those ads into a strategic READ (Dim 2)
 *   3. MarketIntelAgent          — discover + synthesise market & trend signals (Dim 1+3)
 *   4. GrowthStrategistAgent     — synthesise the brand's OWN performance into a
 *                                  growth brief (best times, hooks, CTA lift, …)
 *
 * Each brand AND each stage runs in isolation — one brand's failure (or one
 * stage's) never blocks the others. StrategistAgent reads whichever is_current
 * briefs exist on its next monthly calendar build.
 *
 * Schedule (bootstrap/app.php): every Monday 03:00 UTC, after Optimizer (02:00)
 * so the next StrategistAgent.run picks up every signal together (and the
 * growth stage sees Optimizer's fresh mix for context).
 *
 * Flags let an operator re-run a single stage without re-fetching everything:
 *   --synthesis-only  → skip the raw ad pull; just re-synthesise (Dim 2 + Dim 1+3 + growth)
 *   --market-only     → run ONLY the market/trend stage (Dim 1+3)
 *   --growth-only     → run ONLY the growth stage
 */
class IntelRefresh extends Command
{
    protected $signature = 'intel:refresh
                            {--brand= : Run for a single brand id (default = all brands)}
                            {--limit=200 : Max brands to process}
                            {--synthesis-only : Skip the raw ad pull; only re-synthesise the briefs}
                            {--market-only : Run only the market/trend stage}
                            {--growth-only : Run only the growth-strategy stage}';

    protected $description = 'Refresh the per-brand Strategy Briefing: competitor ads + competitor-strategy synthesis + market/trend intel + growth strategy.';

    public function handle(
        CompetitorIntelAgent $intel,
        CompetitorStrategistAgent $strategist,
        MarketIntelAgent $market,
        GrowthStrategistAgent $growth,
    ): int {
        $brandId = $this->option('brand');
        $limit = max(1, (int) $this->option('limit'));
        $synthesisOnly = (bool) $this->option('synthesis-only');
        $marketOnly = (bool) $this->option('market-only');
        $growthOnly = (bool) $this->option('growth-only');

        $query = Brand::query()->whereNull('archived_at');

        // The raw-ad + competitor-strategy stages need a competitor config; the
        // market + growth stages do not. When market-only/growth-only, don't
        // require a competitor config — gate on the agent's own enable checks.
        if (! $marketOnly && ! $growthOnly) {
            $query->whereNotNull('competitor_intel_config');
        }

        if ($brandId) {
            $query->where('id', $brandId);
        }

        $brands = $query->limit($limit)->get();

        $this->info(sprintf('intel:refresh — scanning %d brand(s)%s', $brands->count(), $this->modeLabel()));

        $totals = ['ads_ok' => 0, 'strat_ok' => 0, 'market_ok' => 0, 'growth_ok' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($brands as $brand) {
            $this->line(sprintf('-> brand#%d %s', $brand->id, $brand->name));

            // Stage 1: raw competitor ad pull (skipped in synthesis-only / market-only / growth-only).
            if (! $synthesisOnly && ! $marketOnly && ! $growthOnly) {
                $this->runStage('ads', fn () => $intel->run($brand, []), $totals, 'ads_ok');
            }

            // Stage 2: competitor-strategy synthesis (Dim 2). Skipped in market-only / growth-only.
            if (! $marketOnly && ! $growthOnly) {
                $this->runStage('strategy', fn () => $strategist->run($brand, []), $totals, 'strat_ok');
            }

            // Stage 3: market & trend intel (Dim 1+3). Skipped in growth-only.
            if (! $growthOnly) {
                $this->runStage('market', fn () => $market->run($brand, []), $totals, 'market_ok');
            }

            // Stage 4: growth strategy (own-performance). Skipped in market-only.
            if (! $marketOnly) {
                $this->runStage('growth', fn () => $growth->run($brand, []), $totals, 'growth_ok');
            }
        }

        $this->line('');
        $this->info(sprintf(
            'done — ads=%d strategy=%d market=%d growth=%d skipped=%d failed=%d',
            $totals['ads_ok'], $totals['strat_ok'], $totals['market_ok'], $totals['growth_ok'], $totals['skipped'], $totals['failed'],
        ));

        return self::SUCCESS;
    }

    /**
     * Run one stage in isolation, tally the outcome, and never let a thrown
     * exception abort the brand loop.
     *
     * @param  callable():\App\Agents\AgentResult  $fn
     * @param  array<string,int>  $totals
     */
    private function runStage(string $label, callable $fn, array &$totals, string $okKey): void
    {
        try {
            $result = $fn();
            if ($result->ok) {
                if (! empty($result->data['skipped'])) {
                    $totals['skipped']++;
                    $this->line(sprintf('   %s skipped: %s', $label, $result->data['reason'] ?? 'unknown'));
                } else {
                    $totals[$okKey]++;
                    $this->line(sprintf('   %s ok: %s', $label, $this->summarise($label, $result->data)));
                }
            } else {
                $totals['failed']++;
                $this->warn(sprintf('   %s failed: %s', $label, $result->errorMessage));
            }
        } catch (\Throwable $e) {
            $totals['failed']++;
            $this->warn(sprintf('   %s crashed: %s', $label, substr($e->getMessage(), 0, 160)));
        }
    }

    /** @param array<string,mixed> $data */
    private function summarise(string $label, array $data): string
    {
        return match ($label) {
            'ads' => sprintf(
                'meta=%d/%d li=%d/%d errors=%d',
                $data['meta_inserted'] ?? 0, $data['meta_fetched'] ?? 0,
                $data['linkedin_inserted'] ?? 0, $data['linkedin_fetched'] ?? 0,
                $data['errors'] ?? 0,
            ),
            'strategy' => sprintf(
                'ads=%d themes=%d competitors=%d whitespace=%d',
                $data['source_ad_count'] ?? 0, $data['theme_count'] ?? 0,
                $data['competitor_count'] ?? 0, $data['whitespace_count'] ?? 0,
            ),
            'market' => sprintf(
                'signals=%d/%d brief=%s trends=%d',
                $data['signals_inserted'] ?? 0, $data['signals_fetched'] ?? 0,
                ! empty($data['brief_written']) ? 'yes' : 'no', $data['trend_count'] ?? 0,
            ),
            'growth' => sprintf(
                'posts=%d hooks=%d cta_signal=%s platforms=%d goals=%d',
                $data['post_count'] ?? 0, $data['hook_count'] ?? 0,
                $data['cta_signal'] ?? 'no', $data['platforms'] ?? 0, $data['goals'] ?? 0,
            ),
            default => '',
        };
    }

    private function modeLabel(): string
    {
        if ($this->option('growth-only')) {
            return ' [growth-only]';
        }
        if ($this->option('market-only')) {
            return ' [market-only]';
        }
        if ($this->option('synthesis-only')) {
            return ' [synthesis-only]';
        }

        return '';
    }
}
