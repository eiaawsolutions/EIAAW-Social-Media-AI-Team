<?php

namespace App\Services\Growth;

/**
 * ε-greedy posting-hour selection for calendar entries the operator did NOT pin.
 *
 * WHY THIS EXISTS
 * ---------------
 * GrowthStrategistAgent::computeBestPostingTimes learns "the best hour to post"
 * from the hours our own posts actually went out at. That only produces
 * knowledge if those hours VARY for a reason. On 2026-08-01, prod brand#1 had
 * 606 of 661 calendar entries with a NULL scheduled_time, so almost every post
 * took the hardcoded 09:00 fallback — and the learner dutifully concluded that
 * Instagram's best hour was 01:00 UTC (= the 09:00 brand-local fallback) off a
 * sample of 3. The system was reading back its own default and calling it a
 * discovery.
 *
 * A bandit needs an explore arm. This class is that arm: for unpinned entries it
 * spends `epsilon` of the time sampling a candidate hour, and the rest of the
 * time exploiting the best hour the brief has actually measured. Every decision
 * is labelled (see ScheduledPost.scheduling_strategy) so the learner can later
 * distinguish evidence from accident.
 *
 * CANDIDATE HOURS ARE A SEARCH SPACE, NOT A CLAIM
 * -----------------------------------------------
 * The per-platform candidate lists below are the hours worth TESTING for a
 * platform — daypart spread across morning / midday / evening / late. They are
 * deliberately NOT presented as "best times to post"; this codebase does not
 * assert audience-timing facts it has not measured. The data decides which
 * candidate wins; this class only guarantees each gets tried.
 *
 * All hours are BRAND-LOCAL. PostsAutoScheduleApproved composes them with the
 * entry's date in the brand timezone before converting to UTC.
 *
 * Pure + deterministic: the same (platform, seed) always yields the same
 * decision, so a re-run of the auto-scheduler, a --dry-run preview, and the real
 * write all agree. No Random/shuffle — reproducibility is what makes this an
 * experiment rather than drift.
 */
final class TimeSlotExplorer
{
    /**
     * Exploration space per platform (brand-local hours, 0–23).
     *
     * @var array<string,array<int,int>>
     */
    private const CANDIDATE_HOURS = [
        'instagram' => [8, 12, 18, 21],
        'facebook' => [9, 13, 19, 21],
        'linkedin' => [8, 10, 12, 17],
        'tiktok' => [12, 16, 19, 22],
        'youtube' => [12, 17, 20],
        'threads' => [9, 12, 19, 21],
        'x' => [8, 12, 17, 21],
        'pinterest' => [14, 20, 22],
    ];

    /** Used when the platform has no dedicated list. */
    private const DEFAULT_CANDIDATES = [8, 12, 17, 20];

    /** The legacy hardcoded fallback, kept as the terminal default. */
    public const LEGACY_FALLBACK_HOUR = 9;

    public const STRATEGY_EXPLORE = 'explore';
    public const STRATEGY_EXPLOIT = 'exploit';
    public const STRATEGY_DEFAULT = 'default_fallback';

    /**
     * The hours worth testing for a platform. Falls back to a generic daypart
     * spread for platforms we have no dedicated list for.
     *
     * @return array<int,int>
     */
    public static function candidatesFor(string $platform): array
    {
        return self::CANDIDATE_HOURS[strtolower(trim($platform))] ?? self::DEFAULT_CANDIDATES;
    }

    /**
     * Pick the hour for one unpinned entry.
     *
     * @param  string    $platform     target platform (candidate space lookup)
     * @param  int       $seed         stable per-decision seed (use the draft id)
     * @param  int|null  $exploitHour  best measured hour from the brief, or null
     * @param  float     $epsilon      probability of exploring, clamped to [0,1]
     * @return array{hour:int,strategy:string}
     */
    public static function decide(string $platform, int $seed, ?int $exploitHour, float $epsilon): array
    {
        $epsilon = max(0.0, min(1.0, $epsilon));
        $exploitHour = self::normaliseHour($exploitHour);

        // Nothing measured yet — every decision is exploration, because the
        // alternative is another month of re-recording the 09:00 default.
        if ($exploitHour === null) {
            return $epsilon <= 0.0
                ? ['hour' => self::LEGACY_FALLBACK_HOUR, 'strategy' => self::STRATEGY_DEFAULT]
                : ['hour' => self::sample($platform, $seed), 'strategy' => self::STRATEGY_EXPLORE];
        }

        // Draw once from the decision seed. Separate salt from the candidate
        // sampler so the explore/exploit coin and the chosen arm are independent.
        if (self::unitFloat($seed, 'coin') < $epsilon) {
            return ['hour' => self::sample($platform, $seed), 'strategy' => self::STRATEGY_EXPLORE];
        }

        return ['hour' => $exploitHour, 'strategy' => self::STRATEGY_EXPLOIT];
    }

    /** Deterministically choose one candidate hour for this platform + seed. */
    private static function sample(string $platform, int $seed): int
    {
        $candidates = self::candidatesFor($platform);
        $index = (int) floor(self::unitFloat($seed, 'arm:'.$platform) * count($candidates));

        // Guard the closed-interval edge: unitFloat can return exactly 1.0 only
        // via float rounding, which would index one past the end.
        return $candidates[min($index, count($candidates) - 1)];
    }

    /**
     * A stable pseudo-random value in [0,1) from an integer seed and a salt.
     * crc32 is not a good hash but it is a perfectly good deterministic spreader
     * for a 3-4 arm bandit, and it needs no extension.
     */
    private static function unitFloat(int $seed, string $salt): float
    {
        return (crc32($salt.':'.$seed) % 100000) / 100000;
    }

    /** Reject out-of-range hours rather than composing an invalid time string. */
    private static function normaliseHour(?int $hour): ?int
    {
        if ($hour === null || $hour < 0 || $hour > 23) {
            return null;
        }

        return $hour;
    }
}
