<?php

namespace App\Agents;

use App\Agents\Prompts\MarketIntelPrompt;
use App\Models\Brand;
use App\Models\MarketSignal;
use App\Models\MarketTrendBrief;
use App\Services\Intel\FirecrawlSearchClient;
use App\Services\Intel\MarketSignalNormalizer;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly per-brand market & trend intelligence. Discovers live industry/market
 * + trend signals via Firecrawl search, runs every result through the
 * MarketSignalNormalizer verification gate (URL + fetched_at + recency, or
 * DISCARD), upserts the survivors into market_signals, then synthesises a
 * verified-only MarketTrendBrief the Strategist reads at calendar-build.
 *
 * Default-OFF (config services.market_intel.enabled) — the only live external
 * fetch path; opt-in per env, then per brand. Soft-fail throughout: any failure
 * leaves the prior is_current brief in place.
 *
 * Truthfulness: ingestion gate (MarketSignalNormalizer) + post-synthesis
 * evidence-id filter (drop any trend whose cited ids don't resolve to real
 * signals). If zero trends survive, NO brief is written — the Strategist block
 * self-suppresses rather than injecting a hollow header.
 */
class MarketIntelAgent extends BaseAgent
{
    /** No readiness gating — runs in the weekly intel beat. */
    protected array $requiredStages = [];

    public function __construct(
        LlmGateway $llm,
        private readonly FirecrawlSearchClient $search,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'market_intel'; }
    public function promptVersion(): string { return MarketIntelPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        if (! (bool) config('services.market_intel.enabled', false)) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'market_intel disabled']);
        }

        $config = $brand->market_intel_config ?? [];
        if (array_key_exists('enabled', $config) && ! $config['enabled']) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'disabled per brand']);
        }

        $industry = trim((string) $brand->industry);
        if ($industry === '') {
            return AgentResult::ok(['skipped' => true, 'reason' => 'brand has no industry set']);
        }

        $maxQueries = (int) config('services.market_intel.max_queries_per_brand', 6);
        $maxResults = (int) config('services.market_intel.max_results_per_query', 8);
        $recencyDays = (int) config('services.market_intel.signal_recency_days', 21);
        $retentionDays = (int) config('services.market_intel.retention_days', 30);

        $queries = self::buildQueries($industry, self::deriveGeoTerms($brand), $maxQueries);

        // ── Ingest: search → gate → upsert ──────────────────────────────
        $inserted = 0;
        $fetched = 0;
        foreach ($queries as $q) {
            $rows = $this->search->search($q['query'], $maxResults);
            $fetched += count($rows);
            foreach ($rows as $row) {
                $payload = MarketSignalNormalizer::fromSearchResult(
                    row: $row,
                    brandId: $brand->id,
                    workspaceId: $brand->workspace_id,
                    query: $q['query'],
                    signalClass: $q['class'],
                    recencyDays: $recencyDays,
                );
                if ($payload === null) {
                    continue; // failed verification — discarded, never stored
                }
                $inserted += $this->upsertSignal($payload);
            }
        }

        // Prune expired signals so the table stays bounded (same as CompetitorIntelAgent).
        MarketSignal::query()
            ->where('brand_id', $brand->id)
            ->where('expires_at', '<=', now())
            ->delete();

        // ── Synthesise over verified, in-window signals only ────────────
        $signals = MarketSignal::query()
            ->where('brand_id', $brand->id)
            ->where('expires_at', '>', now())
            ->orderByDesc('observed_at')
            ->limit(40)
            ->get(['id', 'signal_class', 'title', 'snippet', 'source_url', 'published_at']);

        if ($signals->isEmpty()) {
            return AgentResult::ok([
                'brand_id' => $brand->id,
                'signals_fetched' => $fetched,
                'signals_inserted' => $inserted,
                'brief_written' => false,
                'reason' => 'no verified signals',
            ]);
        }

        $allowedIds = $signals->pluck('id')->map(fn ($i) => (int) $i)->all();

        // id → the text the model actually saw for each signal (title + snippet),
        // so the numeric-claim guard can confirm a trend's figures came from a
        // cited signal rather than being invented.
        $signalTexts = $signals->mapWithKeys(fn (MarketSignal $s): array => [
            (int) $s->id => trim((string) $s->title).' '.trim((string) $s->snippet),
        ])->all();

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: MarketIntelPrompt::system(),
            userMessage: $this->buildUserMessage($brand, $signals),
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 4000,
            jsonSchema: MarketIntelPrompt::schema(),
            agentRole: $this->role(),
            inputSurface: 'scraped', // search results are external/untrusted content
        );

        $payload = $result->parsedJson;
        if (! is_array($payload)) {
            return AgentResult::fail('Market intel synthesis came back empty. Prior brief retained.');
        }

        // Post-synthesis evidence filter: drop any trend whose cited ids don't
        // resolve to real signals; keep only the real ids on survivors.
        $trends = self::filterTrendsByEvidence($payload['trends'] ?? [], $allowedIds, $signalTexts);
        $verifiedSignalCount = self::countCitedSignals($trends);

        if ($trends === [] || $verifiedSignalCount === 0) {
            // Nothing verifiable to say — do NOT write a hollow brief. The
            // Strategist block stays suppressed; the prior brief (if any) is
            // left untouched so we don't blank out last week's good read.
            return AgentResult::ok([
                'brand_id' => $brand->id,
                'signals_fetched' => $fetched,
                'signals_inserted' => $inserted,
                'brief_written' => false,
                'reason' => 'no trend survived the evidence gate',
            ], [
                'model' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => $result->costUsd,
            ]);
        }

        $seasonal = self::cleanSeasonalMoments($payload['seasonal_moments'] ?? []);
        $startsOn = Carbon::now()->subDays($recencyDays);
        $endsOn = Carbon::now();

        DB::transaction(function () use (
            $brand, $startsOn, $endsOn, $payload, $trends, $seasonal,
            $verifiedSignalCount, $result
        ): void {
            MarketTrendBrief::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            MarketTrendBrief::create([
                'brand_id' => $brand->id,
                'workspace_id' => $brand->workspace_id,
                'is_current' => true,
                'window_starts_on' => $startsOn->toDateString(),
                'window_ends_on' => $endsOn->toDateString(),
                'market_summary' => isset($payload['market_summary']) ? (string) $payload['market_summary'] : null,
                'trends' => $trends,
                'seasonal_moments' => $seasonal,
                'verified_signal_count' => $verifiedSignalCount,
                'summary' => isset($payload['summary']) ? (string) $payload['summary'] : null,
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => (float) ($result->costUsd ?? 0),
            ]);
        });

        return AgentResult::ok([
            'brand_id' => $brand->id,
            'signals_fetched' => $fetched,
            'signals_inserted' => $inserted,
            'brief_written' => true,
            'trend_count' => count($trends),
            'verified_signal_count' => $verifiedSignalCount,
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /**
     * Build the search query set from industry + geo terms. Pure + deterministic
     * (testable DB-light). Returns at most $max queries, each tagged with the
     * signal_class it targets.
     *
     * @param  array<int,string>  $geoTerms  country / region phrases (may be empty)
     * @return array<int,array{query:string,class:string}>
     */
    public static function buildQueries(string $industry, array $geoTerms, int $max): array
    {
        $industry = trim($industry);
        if ($industry === '') {
            return [];
        }

        $primaryGeo = $geoTerms[0] ?? '';
        $geoSuffix = $primaryGeo !== '' ? " {$primaryGeo}" : '';

        // Order matters: most valuable queries first, since we cap at $max.
        $candidates = [
            ['query' => "{$industry} industry trends 2026{$geoSuffix}", 'class' => MarketSignalNormalizer::CLASS_INDUSTRY_TREND],
            ['query' => "{$industry} market news{$geoSuffix}", 'class' => MarketSignalNormalizer::CLASS_MARKET_NEWS],
            ['query' => "{$industry} social media trends 2026", 'class' => MarketSignalNormalizer::CLASS_INDUSTRY_TREND],
            ['query' => "{$industry} consumer trends{$geoSuffix}", 'class' => MarketSignalNormalizer::CLASS_INDUSTRY_TREND],
            ['query' => "{$industry} seasonal campaigns{$geoSuffix}", 'class' => MarketSignalNormalizer::CLASS_SEASONAL_TOPICAL],
            ['query' => "{$industry} marketing trends 2026", 'class' => MarketSignalNormalizer::CLASS_INDUSTRY_TREND],
        ];

        return array_slice($candidates, 0, max(1, $max));
    }

    /**
     * Derive geo phrases from the brand's operator-supplied facts. Prefers the
     * primary business location's country, then audience geo_focus, then any
     * other location country. Pure + DB-light (operates on already-loaded
     * brand attributes).
     *
     * @return array<int,string>  deduped geo phrases, primary first
     */
    public static function deriveGeoTerms(Brand $brand): array
    {
        $terms = [];

        $locations = (array) ($brand->business_locations ?? []);
        // Primary location first.
        usort($locations, fn ($a, $b) => (int) ! empty($b['is_primary'] ?? false) <=> (int) ! empty($a['is_primary'] ?? false));
        foreach ($locations as $loc) {
            if (! is_array($loc)) {
                continue;
            }
            $country = trim((string) ($loc['country'] ?? ''));
            if ($country !== '') {
                $terms[$country] = true;
            }
        }

        $geoFocus = trim((string) (($brand->audience_profile['geo_focus'] ?? '')));
        if ($geoFocus !== '') {
            // Use the geo_focus as-is (e.g. "Klang Valley, Malaysia") — it's
            // operator-authored and more specific than a bare country.
            $terms = [$geoFocus => true] + $terms;
        }

        return array_keys($terms);
    }

    /**
     * Keep only trends that cite at least one REAL signal id. On survivors,
     * intersect evidence_signal_ids down to the allowed set (drop hallucinated
     * ids). Mirrors ResearcherAgent's source_ids defense.
     *
     * @param  array<int,mixed>  $trends
     * @param  array<int,int>    $allowedIds
     * @return array<int,array<string,mixed>>
     */
    public static function filterTrendsByEvidence(array $trends, array $allowedIds, array $signalTexts = []): array
    {
        $allowed = array_flip(array_map('intval', $allowedIds));
        $out = [];

        foreach ($trends as $trend) {
            if (! is_array($trend)) {
                continue;
            }
            $name = trim((string) ($trend['trend'] ?? ''));
            if ($name === '') {
                continue;
            }
            $ids = is_array($trend['evidence_signal_ids'] ?? null) ? $trend['evidence_signal_ids'] : [];
            $validIds = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($allowed[$id])) {
                    $validIds[$id] = true;
                }
            }
            $validIds = array_keys($validIds);
            if ($validIds === []) {
                continue; // uncited or only-hallucinated-citation trend — discard
            }

            $why = trim((string) ($trend['why_relevant'] ?? ''));
            $angle = trim((string) ($trend['suggested_angle'] ?? ''));

            // Numeric-claim guard: a statistic in the narrative must appear in at
            // least ONE of this trend's cited signals' text. Citation-ID validity
            // alone doesn't stop the model asserting "65% growth" that no signal
            // supports — so when signal texts are supplied, drop any trend whose
            // why_relevant/suggested_angle invents a number. Skipped when no
            // signalTexts map is given (backward-compatible).
            if ($signalTexts !== []) {
                $citedText = '';
                foreach ($validIds as $id) {
                    $citedText .= ' '.(string) ($signalTexts[$id] ?? '');
                }
                if (! self::numericClaimsGrounded($why.' '.$angle, $citedText)) {
                    continue;
                }
            }

            $out[] = [
                'trend' => $name,
                'evidence_signal_ids' => $validIds,
                'why_relevant' => $why,
                'suggested_angle' => $angle,
            ];
        }

        return $out;
    }

    /**
     * True when every number that appears in $narrative also appears (as a
     * digit run) in $evidence. A narrative with no numbers is trivially grounded.
     * Pure — used to drop trends that invent a statistic no cited signal supports.
     * Compares bare digit runs so "40%", "40 percent", and "up 40 points" all
     * match an evidence "40"; we intentionally ignore the unit, only guarding
     * that the figure itself was actually present in the source.
     */
    public static function numericClaimsGrounded(string $narrative, string $evidence): bool
    {
        preg_match_all('/\d+/', $narrative, $narrativeNums);
        $nums = $narrativeNums[0] ?? [];
        if ($nums === []) {
            return true;
        }

        preg_match_all('/\d+/', $evidence, $evidenceNums);
        $evidenceSet = array_flip($evidenceNums[0] ?? []);

        foreach ($nums as $n) {
            if (! isset($evidenceSet[$n])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Count distinct signal ids cited across surviving trends — the
     * verified_signal_count provenance floor.
     *
     * @param  array<int,array<string,mixed>>  $trends
     */
    public static function countCitedSignals(array $trends): int
    {
        $ids = [];
        foreach ($trends as $trend) {
            foreach ((array) ($trend['evidence_signal_ids'] ?? []) as $id) {
                $ids[(int) $id] = true;
            }
        }

        return count($ids);
    }

    /** @return array<int,array<string,mixed>> trimmed seasonal moments */
    private static function cleanSeasonalMoments(mixed $moments): array
    {
        if (! is_array($moments)) {
            return [];
        }
        $out = [];
        foreach ($moments as $m) {
            if (! is_array($m)) {
                continue;
            }
            $moment = trim((string) ($m['moment'] ?? ''));
            if ($moment === '') {
                continue;
            }
            $out[] = [
                'moment' => $moment,
                'window' => trim((string) ($m['window'] ?? '')),
                'why_relevant' => trim((string) ($m['why_relevant'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Upsert one verified signal. Returns 1 if a new row was inserted, 0 if it
     * matched an existing (brand_id, dedup_hash) and was refreshed. Mirrors
     * CompetitorIntelAgent::upsertRows.
     *
     * @param  array<string,mixed>  $payload
     */
    private function upsertSignal(array $payload): int
    {
        $existed = DB::table('market_signals')
            ->where('brand_id', $payload['brand_id'])
            ->where('dedup_hash', $payload['dedup_hash'])
            ->exists();

        if ($existed) {
            DB::table('market_signals')
                ->where('brand_id', $payload['brand_id'])
                ->where('dedup_hash', $payload['dedup_hash'])
                ->update([
                    'observed_at' => $payload['observed_at'],
                    'expires_at' => $payload['expires_at'],
                    'fetched_at' => $payload['fetched_at'],
                    'updated_at' => now(),
                ]);

            return 0;
        }

        try {
            MarketSignal::create($payload);

            return 1;
        } catch (\Throwable $e) {
            Log::warning('MarketIntelAgent: signal insert failed', [
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return 0;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int,MarketSignal>  $signals
     */
    private function buildUserMessage(Brand $brand, \Illuminate\Support\Collection $signals): string
    {
        $lines = $signals->map(function (MarketSignal $s): string {
            $title = trim((string) $s->title);
            $snippet = mb_substr(trim((string) $s->snippet), 0, 280);
            $when = $s->published_at?->format('M j, Y') ?? 'recent';

            return "[id={$s->id} · {$s->signal_class} · {$when}] {$title}\n  {$snippet}\n  source: {$s->source_url}";
        })->implode("\n\n");

        $validIds = $signals->pluck('id')->implode(', ');

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}

# VERIFIED market & trend signals (cite ONLY these ids)
VALID signal ids you may cite: {$validIds}

{$lines}

Synthesise the brief per the schema. Every trend MUST cite ≥1 of the valid ids above. Do not invent statistics not present in a cited signal.
MSG;
    }
}
