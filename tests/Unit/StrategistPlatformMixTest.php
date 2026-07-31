<?php

namespace Tests\Unit;

use App\Agents\StrategistAgent;
use Tests\TestCase;

/**
 * StrategistAgent platform allocation.
 *
 * Background (prod audit 2026-07-31): the Optimizer has always written a
 * `platform_mix` onto StrategistRecommendation, but the Strategist ignored it
 * and hard-coded an EVEN split across every active connection:
 *
 *     'platform_mix' => array_fill_keys($activePlatforms, 1.0 / count(...))
 *
 * That even floor is why a surface delivering 139 impressions across 68 posts
 * (Facebook) kept the same share of the month's cadence as one delivering
 * 3,065 (Instagram). These tests lock the reconciliation rules. Pure + DB-free.
 */
class StrategistPlatformMixTest extends TestCase
{
    public function test_falls_back_to_an_even_split_when_there_is_no_recommendation(): void
    {
        $mix = StrategistAgent::resolvePlatformMix(['instagram', 'linkedin', 'threads'], null);

        foreach ($mix as $weight) {
            $this->assertEqualsWithDelta(1 / 3, $weight, 0.0001);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_falls_back_to_an_even_split_when_the_recommendation_is_empty(): void
    {
        $mix = StrategistAgent::resolvePlatformMix(['instagram', 'linkedin'], []);

        $this->assertEqualsWithDelta(0.5, $mix['instagram'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $mix['linkedin'], 0.0001);
    }

    public function test_uses_the_recommended_weights_when_present(): void
    {
        $mix = StrategistAgent::resolvePlatformMix(
            ['instagram', 'facebook'],
            ['instagram' => 0.98, 'facebook' => 0.02],
        );

        $this->assertGreaterThan(0.9, $mix['instagram']);
        $this->assertLessThan(0.05, $mix['facebook']);
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_platforms_no_longer_connected_are_dropped_and_the_rest_renormalised(): void
    {
        // The reco was built while tiktok was still connected; it isn't now.
        $mix = StrategistAgent::resolvePlatformMix(
            ['instagram', 'linkedin'],
            ['instagram' => 0.3, 'linkedin' => 0.3, 'tiktok' => 0.4],
        );

        $this->assertArrayNotHasKey('tiktok', $mix);
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
        $this->assertEqualsWithDelta(0.5, $mix['instagram'], 0.0001);
    }

    public function test_a_newly_connected_platform_absent_from_the_reco_still_gets_a_share(): void
    {
        // threads was connected after the last Optimizer run — it must not be
        // silently excluded from the month just because it has no history.
        $mix = StrategistAgent::resolvePlatformMix(
            ['instagram', 'linkedin', 'threads'],
            ['instagram' => 0.6, 'linkedin' => 0.4],
        );

        $this->assertArrayHasKey('threads', $mix);
        $this->assertGreaterThan(0.0, $mix['threads']);
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_every_active_platform_is_always_present_in_the_mix(): void
    {
        $active = ['instagram', 'linkedin', 'threads', 'facebook'];
        $mix = StrategistAgent::resolvePlatformMix($active, ['instagram' => 1.0]);

        $this->assertSame($active, array_keys($mix));
        $this->assertEqualsWithDelta(1.0, array_sum($mix), 0.0001);
    }

    public function test_no_active_platforms_yields_an_empty_mix_not_a_divide_by_zero(): void
    {
        $this->assertSame([], StrategistAgent::resolvePlatformMix([], ['instagram' => 1.0]));
    }

    public function test_a_reco_of_all_zeroes_degrades_to_an_even_split(): void
    {
        $mix = StrategistAgent::resolvePlatformMix(
            ['instagram', 'linkedin'],
            ['instagram' => 0.0, 'linkedin' => 0.0],
        );

        $this->assertEqualsWithDelta(0.5, $mix['instagram'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $mix['linkedin'], 0.0001);
    }
}
