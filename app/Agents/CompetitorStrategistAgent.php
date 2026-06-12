<?php

namespace App\Agents;

use App\Agents\Prompts\CompetitorStrategistPrompt;
use App\Models\Brand;
use App\Models\CompetitorAd;
use App\Models\CompetitorStrategyBrief;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Synthesises the raw competitor_ads we already collect weekly into a STRATEGIC
 * read (pillars, positioning, share-of-voice, whitespace) and writes one
 * is_current CompetitorStrategyBrief the Strategist reads at calendar-build.
 *
 * Cost: one Sonnet call per brand per week (~$0.02-0.05). No new ingestion —
 * it reads competitor_ads, so it's cheap and network-free beyond the LLM.
 *
 * Verification (truthfulness contract):
 *   - Soft-skip if too few ads (fewer verified > a fabricated synthesis).
 *   - Drop any synthesised reference to a competitor NOT in the source ads
 *     (the ResearcherAgent hallucination-drop defense).
 *   - Recompute share_of_voice deterministically in PHP from real ad counts —
 *     never trust a model-supplied number — so the headline is always true.
 *
 * Failure mode: soft. An empty/failed synthesis leaves the prior is_current
 * brief in place; the Strategist still has last week's read.
 */
class CompetitorStrategistAgent extends BaseAgent
{
    /** No readiness gating — runs in the weekly intel beat before drafts exist. */
    protected array $requiredStages = [];

    /** Below this, the data is too thin to synthesise a trustworthy strategy. */
    private const MIN_ADS_FOR_SYNTHESIS = 4;

    /** Token-budget cap: synthesise themes, not a database dump. */
    private const MAX_ADS_FED = 60;

    public function role(): string { return 'competitor_strategist'; }
    public function promptVersion(): string { return CompetitorStrategistPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        if (! (bool) config('services.competitor_intel.synthesis_enabled', true)) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'competitor strategy synthesis disabled']);
        }

        $config = $brand->competitor_intel_config ?? [];
        if (array_key_exists('synthesis_enabled', $config) && ! $config['synthesis_enabled']) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'disabled per brand']);
        }

        $windowDays = (int) config('services.competitor_intel.retention_days', 30);
        $startsOn = Carbon::now()->subDays($windowDays);
        $endsOn = Carbon::now();

        $ads = CompetitorAd::query()
            ->where('brand_id', $brand->id)
            ->where('observed_at', '>=', $startsOn)
            ->whereNotNull('body')
            ->orderByDesc('observed_at')
            ->limit(self::MAX_ADS_FED)
            ->get(['competitor_label', 'competitor_handle', 'platform', 'body', 'cta', 'first_seen_at']);

        if ($ads->count() < self::MIN_ADS_FOR_SYNTHESIS) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => sprintf('only %d ad(s) in window (need ≥%d)', $ads->count(), self::MIN_ADS_FOR_SYNTHESIS),
            ]);
        }

        // The authoritative set of competitor labels actually present in the
        // data. Anything the model names outside this set is a hallucination.
        $allowedLabels = self::allowedLabels($ads);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: CompetitorStrategistPrompt::system(),
            userMessage: $this->buildUserMessage($brand, $ads),
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 4000,
            jsonSchema: CompetitorStrategistPrompt::schema(),
            agentRole: $this->role(),
            inputSurface: 'scraped', // competitor ad copy is external/untrusted content
        );

        $payload = $result->parsedJson;
        if (! is_array($payload)) {
            return AgentResult::fail('Competitor strategy synthesis came back empty. Prior brief retained.');
        }

        // Hallucination-drop: keep only competitors that exist in the source ads.
        $dominantThemes = self::filterThemes($payload['dominant_themes'] ?? [], $allowedLabels);
        $positioningMap = self::filterPositioning($payload['positioning_map'] ?? [], $allowedLabels);
        $whitespace = self::cleanStringList($payload['whitespace'] ?? []);

        // share_of_voice is OURS to compute — from the real ad counts, never
        // the model. This guarantees the headline number is evidence-true.
        $shareOfVoice = self::computeShareOfVoice($ads);

        DB::transaction(function () use (
            $brand, $startsOn, $endsOn, $dominantThemes, $positioningMap,
            $shareOfVoice, $whitespace, $payload, $ads, $result
        ): void {
            CompetitorStrategyBrief::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            CompetitorStrategyBrief::create([
                'brand_id' => $brand->id,
                'workspace_id' => $brand->workspace_id,
                'is_current' => true,
                'window_starts_on' => $startsOn->toDateString(),
                'window_ends_on' => $endsOn->toDateString(),
                'dominant_themes' => $dominantThemes,
                'positioning_map' => $positioningMap,
                'share_of_voice' => $shareOfVoice,
                'whitespace' => $whitespace,
                'cadence_notes' => isset($payload['cadence_notes']) ? (string) $payload['cadence_notes'] : null,
                'summary' => isset($payload['summary']) ? (string) $payload['summary'] : null,
                'source_ad_count' => $ads->count(),
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => (float) ($result->costUsd ?? 0),
            ]);
        });

        return AgentResult::ok([
            'brand_id' => $brand->id,
            'source_ad_count' => $ads->count(),
            'theme_count' => count($dominantThemes),
            'competitor_count' => count($positioningMap),
            'whitespace_count' => count($whitespace),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /**
     * The canonical set of competitor labels present in the source ads.
     * A label is the human label when set, else the handle. Lower-cased for
     * case-insensitive matching against the model's output.
     *
     * @param  Collection<int,CompetitorAd>  $ads
     * @return array<string,string>  lowercased => canonical display label
     */
    public static function allowedLabels(Collection $ads): array
    {
        $map = [];
        foreach ($ads as $ad) {
            $label = trim((string) ($ad->competitor_label ?: $ad->competitor_handle));
            if ($label === '') {
                continue;
            }
            $map[mb_strtolower($label)] = $label;
        }

        return $map;
    }

    /**
     * Share-of-voice = each competitor's share of the observed ads in the
     * window, as a percentage summing to ~100. Computed from real counts so it
     * can never be inflated by the model.
     *
     * @param  Collection<int,CompetitorAd>  $ads
     * @return array<string,float>  display label => percentage (1 dp)
     */
    public static function computeShareOfVoice(Collection $ads): array
    {
        $counts = [];
        foreach ($ads as $ad) {
            $label = trim((string) ($ad->competitor_label ?: $ad->competitor_handle));
            if ($label === '') {
                continue;
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $total = array_sum($counts);
        if ($total <= 0) {
            return [];
        }

        $out = [];
        foreach ($counts as $label => $n) {
            $out[$label] = round($n / $total * 100, 1);
        }
        arsort($out);

        return $out;
    }

    /**
     * Drop themes whose competitor list, after intersecting with the allowed
     * set, is empty — i.e. the model attributed a theme only to invented
     * competitors. Surviving themes carry only real competitor labels.
     *
     * @param  array<int,mixed>  $themes
     * @param  array<string,string>  $allowed  lowercased => display label
     * @return array<int,array{theme:string,competitors:array<int,string>}>
     */
    public static function filterThemes(array $themes, array $allowed): array
    {
        $out = [];
        foreach ($themes as $row) {
            if (! is_array($row)) {
                continue;
            }
            $theme = trim((string) ($row['theme'] ?? ''));
            if ($theme === '') {
                continue;
            }
            $competitors = self::intersectLabels((array) ($row['competitors'] ?? []), $allowed);
            if ($competitors === []) {
                continue; // theme attributed only to hallucinated competitors
            }
            $out[] = ['theme' => $theme, 'competitors' => $competitors];
        }

        return $out;
    }

    /**
     * Keep only positioning entries whose competitor_label is in the allowed
     * set (case-insensitive). Hallucinated competitors are dropped entirely.
     *
     * @param  array<int,mixed>  $rows
     * @param  array<string,string>  $allowed
     * @return array<int,array<string,mixed>>
     */
    public static function filterPositioning(array $rows, array $allowed): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['competitor_label'] ?? ''));
            if ($label === '' || ! isset($allowed[mb_strtolower($label)])) {
                continue;
            }
            $out[] = [
                'competitor_label' => $allowed[mb_strtolower($label)], // canonical casing
                'positioning_summary' => trim((string) ($row['positioning_summary'] ?? '')),
                'primary_pillars' => self::cleanStringList($row['primary_pillars'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $labels
     * @param  array<string,string>  $allowed
     * @return array<int,string>  canonical display labels, deduped
     */
    private static function intersectLabels(array $labels, array $allowed): array
    {
        $out = [];
        foreach ($labels as $label) {
            $key = mb_strtolower(trim((string) $label));
            if ($key !== '' && isset($allowed[$key])) {
                $out[$allowed[$key]] = true;
            }
        }

        return array_keys($out);
    }

    /** @return array<int,string> trimmed, non-empty, deduped string list */
    private static function cleanStringList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[$s] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  Collection<int,CompetitorAd>  $ads
     */
    private function buildUserMessage(Brand $brand, Collection $ads): string
    {
        $lines = $ads->map(function (CompetitorAd $ad): string {
            $label = $ad->competitor_label ?: $ad->competitor_handle;
            $body = mb_substr(trim((string) $ad->body), 0, 400);
            $cta = $ad->cta ? " [CTA: {$ad->cta}]" : '';
            $when = $ad->first_seen_at?->format('M j') ?? 'recent';

            return "- ({$ad->platform} · {$label} · {$when}) {$body}{$cta}";
        })->implode("\n");

        $labels = implode(', ', array_values(self::allowedLabels($ads)));

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}

# Competitors present in this sample (use ONLY these labels)
{$labels}

# Observed competitor ads (last {$ads->count()} in the window)
{$lines}

Produce the strategic read per the schema. Reference only the competitor labels listed above. Do not output share-of-voice numbers — the system computes those.
MSG;
    }
}
