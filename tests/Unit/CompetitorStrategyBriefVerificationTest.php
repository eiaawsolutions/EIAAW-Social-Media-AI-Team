<?php

namespace Tests\Unit;

use App\Agents\CompetitorStrategistAgent;
use App\Models\CompetitorAd;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure-function tests (no DB — CompetitorAd instances are built in-memory) for
 * the competitor-strategy synthesis verification: hallucinated competitors are
 * dropped, and share-of-voice is recomputed from real ad counts (never trusted
 * from the model).
 */
class CompetitorStrategyBriefVerificationTest extends TestCase
{
    /** @param array<int,array{label:?string,handle:?string}> $rows */
    private function ads(array $rows): Collection
    {
        return collect($rows)->map(fn ($r) => new CompetitorAd([
            'competitor_label' => $r['label'] ?? null,
            'competitor_handle' => $r['handle'] ?? null,
        ]));
    }

    public function test_allowed_labels_is_case_insensitive_keyed(): void
    {
        $ads = $this->ads([
            ['label' => 'Acme Corp', 'handle' => '111'],
            ['label' => null, 'handle' => 'beta-co'],
        ]);

        $allowed = CompetitorStrategistAgent::allowedLabels($ads);

        $this->assertArrayHasKey('acme corp', $allowed);     // lowercased key
        $this->assertSame('Acme Corp', $allowed['acme corp']); // canonical display value
        $this->assertArrayHasKey('beta-co', $allowed);        // falls back to handle
    }

    public function test_positioning_for_unknown_competitor_is_filtered_out(): void
    {
        $allowed = CompetitorStrategistAgent::allowedLabels($this->ads([
            ['label' => 'Acme Corp', 'handle' => '111'],
        ]));

        $positioning = [
            ['competitor_label' => 'Acme Corp', 'positioning_summary' => 'Premium, price-led', 'primary_pillars' => ['value']],
            ['competitor_label' => 'Ghost Inc', 'positioning_summary' => 'Invented competitor', 'primary_pillars' => []],
        ];

        $kept = CompetitorStrategistAgent::filterPositioning($positioning, $allowed);

        $this->assertCount(1, $kept);
        $this->assertSame('Acme Corp', $kept[0]['competitor_label']);
    }

    public function test_theme_attributed_only_to_hallucinated_competitor_is_dropped(): void
    {
        $allowed = CompetitorStrategistAgent::allowedLabels($this->ads([
            ['label' => 'Acme Corp', 'handle' => '111'],
        ]));

        $themes = [
            ['theme' => 'Sustainability', 'competitors' => ['Acme Corp']],
            ['theme' => 'Made-up theme', 'competitors' => ['Ghost Inc']],
        ];

        $kept = CompetitorStrategistAgent::filterThemes($themes, $allowed);

        $this->assertCount(1, $kept);
        $this->assertSame('Sustainability', $kept[0]['theme']);
        $this->assertSame(['Acme Corp'], $kept[0]['competitors']);
    }

    public function test_share_of_voice_recomputed_from_real_counts_sums_to_100(): void
    {
        // Acme has 3 ads, Beta has 1 → 75% / 25%.
        $ads = $this->ads([
            ['label' => 'Acme Corp', 'handle' => '111'],
            ['label' => 'Acme Corp', 'handle' => '111'],
            ['label' => 'Acme Corp', 'handle' => '111'],
            ['label' => 'Beta Co', 'handle' => '222'],
        ]);

        $sov = CompetitorStrategistAgent::computeShareOfVoice($ads);

        $this->assertSame(75.0, $sov['Acme Corp']);
        $this->assertSame(25.0, $sov['Beta Co']);
        $this->assertEqualsWithDelta(100.0, array_sum($sov), 0.1);
    }
}
