<?php

namespace App\Agents;

use App\Models\Brand;
use App\Models\PostMetric;
use App\Models\StrategistRecommendation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads the last N days of post_metrics for a brand, computes which pillar /
 * format / platform combinations are outperforming, and writes a
 * StrategistRecommendation row that the Strategist consumes on the next
 * calendar build to bias the next month toward what's working.
 *
 * Score formula (per post): impressions + 5*likes + 10*comments + 20*shares + 30*saves.
 * Same weights as the /agency/performance dashboard — saves are by far the
 * highest-intent signal on every visual platform.
 *
 * v1: pure deterministic math (no LLM). The plain-English `summary` is
 * generated locally too; v1.1 layer adds an LLM "what's working" narrative.
 *
 * Optional input:
 *   - window_days (int, default 30)
 */
class OptimizerAgent extends BaseAgent
{
    public function role(): string { return 'optimizer'; }
    public function promptVersion(): string { return 'optimizer.v1.0'; }

    private const DEFAULT_WINDOW_DAYS = 30;

    /** Used to balance "follow what's working" vs "keep variety". 0.5 is moderate bias. */
    private const RECOMMENDATION_WEIGHT = 0.5;

    /** Minimum posts in the window before we generate a recommendation. */
    private const MIN_POSTS_FOR_RECOMMENDATION = 3;

    /** Strategist's defaults — re-applied as the floor for every dimension. */
    private const DEFAULT_PILLAR_MIX = [
        'educational' => 0.30, 'community' => 0.25, 'promotional' => 0.15,
        'behind_the_scenes' => 0.15, 'thought_leadership' => 0.15,
    ];

    private const DEFAULT_FORMAT_MIX = [
        'single_image' => 0.30, 'carousel' => 0.30, 'reel' => 0.20,
        'text_only' => 0.15, 'video' => 0.05,
    ];

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $windowDays = (int) ($input['window_days'] ?? self::DEFAULT_WINDOW_DAYS);
        $endsOn = Carbon::now();
        $startsOn = $endsOn->copy()->subDays($windowDays);

        $rows = $this->latestMetricPerPost($brand, $startsOn);

        if ($rows->count() < self::MIN_POSTS_FOR_RECOMMENDATION) {
            return AgentResult::fail(sprintf(
                'Not enough data: %d post(s) in %d-day window (need ≥%d). Publish more, then re-run.',
                $rows->count(),
                $windowDays,
                self::MIN_POSTS_FOR_RECOMMENDATION,
            ));
        }

        $pillarMix = $this->blendMix($this->scoreByDimension($rows, 'pillar'), self::DEFAULT_PILLAR_MIX);
        $formatMix = $this->blendMix($this->scoreByDimension($rows, 'format'), self::DEFAULT_FORMAT_MIX);
        $platformMix = $this->normaliseMix($this->scoreByDimension($rows, 'platform'));

        $topPerformers = $this->topN($rows, 5);
        $summary = $this->buildSummary($rows, $pillarMix, $formatMix, $platformMix);

        // Demote previous current row + write the new one.
        DB::transaction(function () use ($brand, $startsOn, $endsOn, $pillarMix, $formatMix, $platformMix, $topPerformers, $summary, $rows): void {
            StrategistRecommendation::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            StrategistRecommendation::create([
                'brand_id' => $brand->id,
                'is_current' => true,
                'window_starts_on' => $startsOn->toDateString(),
                'window_ends_on' => $endsOn->toDateString(),
                'pillar_mix' => $pillarMix,
                'format_mix' => $formatMix,
                'platform_mix' => $platformMix,
                'top_performers' => $topPerformers,
                'summary' => $summary,
                'post_count_in_window' => $rows->count(),
                'impressions_total' => (int) $rows->sum(fn ($r) => (int) ($r->impressions ?? 0)),
                'engagement_total' => (int) $rows->sum(fn ($r) => $this->engagement($r)),
            ]);
        });

        return AgentResult::ok([
            'window_days' => $windowDays,
            'post_count' => $rows->count(),
            'pillar_mix' => $pillarMix,
            'format_mix' => $formatMix,
            'platform_mix' => $platformMix,
            'top_count' => count($topPerformers),
            'summary' => $summary,
        ]);
    }

    /**
     * Latest snapshot per scheduled_post in window, joined with the draft
     * + calendar entry so we can attribute by pillar/format.
     *
     * @return \Illuminate\Support\Collection
     */
    private function latestMetricPerPost(Brand $brand, Carbon $startsOn): \Illuminate\Support\Collection
    {
        $latestIds = DB::table('post_metrics')
            ->select(DB::raw('MAX(id) as id'))
            ->where('brand_id', $brand->id)
            ->where('observed_at', '>=', $startsOn)
            ->groupBy('scheduled_post_id')
            ->pluck('id');

        return PostMetric::with(['scheduledPost.draft.calendarEntry'])
            ->whereIn('id', $latestIds)
            ->get();
    }

    private function engagement(PostMetric $m): int
    {
        return (int) (($m->likes ?? 0) + ($m->comments ?? 0) + ($m->shares ?? 0) + ($m->saves ?? 0));
    }

    private function score(PostMetric $m): float
    {
        return (int) ($m->impressions ?? 0)
            + 5 * (int) ($m->likes ?? 0)
            + 10 * (int) ($m->comments ?? 0)
            + 20 * (int) ($m->shares ?? 0)
            + 30 * (int) ($m->saves ?? 0);
    }

    /** @return array<string,float> normalised distribution */
    private function scoreByDimension(\Illuminate\Support\Collection $rows, string $dim): array
    {
        $bag = [];
        foreach ($rows as $m) {
            $value = match ($dim) {
                'pillar' => $m->scheduledPost?->draft?->calendarEntry?->pillar,
                'format' => $m->scheduledPost?->draft?->calendarEntry?->format,
                'platform' => $m->platform,
                default => null,
            };
            if (! $value) continue;
            $bag[$value] = ($bag[$value] ?? 0) + $this->score($m);
        }
        $total = array_sum($bag);
        if ($total <= 0) return [];
        foreach ($bag as $k => $v) {
            $bag[$k] = round($v / $total, 4);
        }
        return $bag;
    }

    /**
     * Blend the data-driven distribution with the default floor so we
     * keep variety even when one pillar is winning hard. Default weights
     * 0.5 each — operator can override via config in v1.1.
     *
     * @param array<string,float> $observed
     * @param array<string,float> $defaults
     * @return array<string,float>
     */
    private function blendMix(array $observed, array $defaults): array
    {
        $w = self::RECOMMENDATION_WEIGHT;
        $blended = [];
        foreach ($defaults as $key => $defaultPct) {
            $observedPct = $observed[$key] ?? 0.0;
            $blended[$key] = round($observedPct * $w + $defaultPct * (1 - $w), 4);
        }
        // Ensure new dimensions in `observed` (shouldn't happen for fixed
        // schemas but defensive) carry through at half-weight.
        foreach ($observed as $key => $val) {
            if (! array_key_exists($key, $blended)) {
                $blended[$key] = round($val * 0.5, 4);
            }
        }
        // Normalise to sum=1.
        $sum = array_sum($blended);
        if ($sum > 0) {
            foreach ($blended as $k => $v) {
                $blended[$k] = round($v / $sum, 4);
            }
        }
        return $blended;
    }

    /** @param array<string,float> $observed */
    private function normaliseMix(array $observed): array
    {
        if (empty($observed)) return [];
        $sum = array_sum($observed);
        if ($sum <= 0) return [];
        $out = [];
        foreach ($observed as $k => $v) {
            $out[$k] = round($v / $sum, 4);
        }
        return $out;
    }

    /** @return array<int, array<string,mixed>> */
    private function topN(\Illuminate\Support\Collection $rows, int $n): array
    {
        return $rows
            ->sortByDesc(fn (PostMetric $m) => $this->score($m))
            ->take($n)
            ->map(fn (PostMetric $m) => [
                'scheduled_post_id' => $m->scheduled_post_id,
                'platform' => $m->platform,
                'pillar' => $m->scheduledPost?->draft?->calendarEntry?->pillar,
                'format' => $m->scheduledPost?->draft?->calendarEntry?->format,
                'score' => (int) $this->score($m),
                'impressions' => $m->impressions,
                'engagement' => $this->engagement($m),
                'preview' => substr((string) ($m->scheduledPost?->draft?->body ?? '—'), 0, 100),
                'url' => $m->scheduledPost?->platform_post_url,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string,float> $pillarMix
     * @param array<string,float> $formatMix
     * @param array<string,float> $platformMix
     */
    private function buildSummary(\Illuminate\Support\Collection $rows, array $pillarMix, array $formatMix, array $platformMix): string
    {
        arsort($pillarMix);
        arsort($formatMix);
        arsort($platformMix);

        $topPillar = array_key_first($pillarMix);
        $topFormat = array_key_first($formatMix);
        $topPlatform = array_key_first($platformMix);

        $totalImpr = (int) $rows->sum(fn ($r) => (int) ($r->impressions ?? 0));
        $totalEng = (int) $rows->sum(fn ($r) => $this->engagement($r));

        return sprintf(
            'Across %d posts: %s reached the most with %s impressions and %s engagement. %s pillar is your best-performing voice; %s format is winning the algorithm. Strategist will weight the next calendar toward this mix.',
            $rows->count(),
            ucfirst((string) $topPlatform),
            number_format($totalImpr),
            number_format($totalEng),
            ucfirst(str_replace('_', ' ', (string) $topPillar)),
            str_replace('_', ' ', (string) $topFormat),
        );
    }
}
