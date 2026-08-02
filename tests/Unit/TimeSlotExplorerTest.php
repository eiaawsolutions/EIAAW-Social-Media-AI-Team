<?php

namespace Tests\Unit;

use App\Services\Growth\TimeSlotExplorer;
use Tests\TestCase;

/**
 * Pure tests for the ε-greedy posting-hour arm.
 *
 * The property that matters is COVERAGE: across many drafts the explorer must
 * actually reach more than one hour. Without that the best-time learner reads
 * back its own default forever — which is exactly what prod was doing (606 of
 * 661 entries unpinned, nearly all landing on the same fallback hour).
 */
class TimeSlotExplorerTest extends TestCase
{
    public function test_decision_is_deterministic_for_the_same_seed(): void
    {
        // The auto-scheduler previews (--dry-run) and then writes; both must agree.
        $a = TimeSlotExplorer::decide('instagram', 4242, 14, 0.3);
        $b = TimeSlotExplorer::decide('instagram', 4242, 14, 0.3);

        $this->assertSame($a, $b);
    }

    public function test_epsilon_zero_always_exploits_a_known_hour(): void
    {
        for ($seed = 0; $seed < 50; $seed++) {
            $d = TimeSlotExplorer::decide('instagram', $seed, 14, 0.0);
            $this->assertSame(TimeSlotExplorer::STRATEGY_EXPLOIT, $d['strategy']);
            $this->assertSame(14, $d['hour']);
        }
    }

    public function test_epsilon_one_always_explores(): void
    {
        for ($seed = 0; $seed < 50; $seed++) {
            $d = TimeSlotExplorer::decide('instagram', $seed, 14, 1.0);
            $this->assertSame(TimeSlotExplorer::STRATEGY_EXPLORE, $d['strategy']);
        }
    }

    public function test_no_measured_hour_falls_back_to_legacy_when_exploration_is_off(): void
    {
        // Belt-and-braces: with exploration disabled and nothing learned, the
        // behaviour must be byte-identical to the pre-change hardcoded 09:00.
        $d = TimeSlotExplorer::decide('instagram', 7, null, 0.0);

        $this->assertSame(TimeSlotExplorer::STRATEGY_DEFAULT, $d['strategy']);
        $this->assertSame(TimeSlotExplorer::LEGACY_FALLBACK_HOUR, $d['hour']);
    }

    public function test_no_measured_hour_explores_when_enabled(): void
    {
        // Cold start is all exploration — there is nothing to exploit yet, and
        // repeating the default is what created the confounded data.
        $d = TimeSlotExplorer::decide('instagram', 7, null, 0.3);

        $this->assertSame(TimeSlotExplorer::STRATEGY_EXPLORE, $d['strategy']);
        $this->assertContains($d['hour'], TimeSlotExplorer::candidatesFor('instagram'));
    }

    public function test_exploration_actually_covers_every_candidate_hour(): void
    {
        // The whole point. If the sampler collapsed onto one arm we would be
        // back to learning from a single hour.
        $seen = [];
        for ($seed = 1; $seed <= 400; $seed++) {
            $d = TimeSlotExplorer::decide('instagram', $seed, null, 1.0);
            $seen[$d['hour']] = true;
        }

        $candidates = TimeSlotExplorer::candidatesFor('instagram');
        sort($candidates);
        $found = array_keys($seen);
        sort($found);

        $this->assertSame($candidates, $found, 'every candidate hour must be reachable');
    }

    public function test_explore_exploit_split_tracks_epsilon(): void
    {
        $explored = 0;
        $n = 2000;
        for ($seed = 1; $seed <= $n; $seed++) {
            if (TimeSlotExplorer::decide('linkedin', $seed, 10, 0.3)['strategy'] === TimeSlotExplorer::STRATEGY_EXPLORE) {
                $explored++;
            }
        }

        // Deterministic hashing, not a true RNG — allow a generous band.
        $this->assertGreaterThan(0.20 * $n, $explored);
        $this->assertLessThan(0.42 * $n, $explored);
    }

    public function test_every_candidate_hour_is_a_valid_hour(): void
    {
        foreach (['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'threads', 'x', 'pinterest', 'unknown_platform'] as $platform) {
            foreach (TimeSlotExplorer::candidatesFor($platform) as $hour) {
                $this->assertGreaterThanOrEqual(0, $hour);
                $this->assertLessThanOrEqual(23, $hour);
            }
        }
    }

    public function test_unknown_platform_gets_the_default_space(): void
    {
        $this->assertNotEmpty(TimeSlotExplorer::candidatesFor('mastodon'));
    }

    public function test_platform_lookup_is_case_insensitive(): void
    {
        $this->assertSame(
            TimeSlotExplorer::candidatesFor('instagram'),
            TimeSlotExplorer::candidatesFor('Instagram'),
        );
    }

    public function test_out_of_range_measured_hour_is_rejected_not_composed(): void
    {
        // A corrupt brief must not produce "27:00:00" in a time string.
        $d = TimeSlotExplorer::decide('instagram', 3, 27, 0.0);
        $this->assertSame(TimeSlotExplorer::STRATEGY_DEFAULT, $d['strategy']);
        $this->assertSame(TimeSlotExplorer::LEGACY_FALLBACK_HOUR, $d['hour']);
    }
}
