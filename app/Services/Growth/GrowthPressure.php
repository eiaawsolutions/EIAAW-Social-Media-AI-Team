<?php

namespace App\Services\Growth;

/**
 * How hard the system should push on a growth goal, as a continuous scalar in
 * [0,1] — and which actuators that unlocks.
 *
 * WHAT THIS REPLACES
 * ------------------
 * BrandGrowthGoal::paceStatus() returns one of three strings with a ±10pp
 * tolerance. That made escalation impossible in two ways:
 *
 *   1. NON-MONOTONIC. A goal 11 points behind pace and a goal 47 points behind
 *      pace both read 'lagging', and StrategistAgent::renderLaggingGoalsBlock
 *      emitted byte-identical text for each. Prod brand#1's Instagram goal sat
 *      at 0% progress against 47.4% expected for weeks and never escalated.
 *   2. MEMORYLESS. Pace was recomputed and discarded every week, so a goal
 *      lagging for its 7th consecutive evaluation was indistinguishable from one
 *      lagging for the first.
 *
 * Pressure fixes both: magnitude enters via the gap, persistence via the streak,
 * and deadline proximity via urgency.
 *
 * TRUTHFULNESS
 * ------------
 * Pressure is null whenever there is no real progress reading. Absence of a
 * reading is NOT evidence of lagging, and it must never manufacture pressure —
 * the same rule that makes paceStatus() return null rather than guess. A goal we
 * cannot measure applies no force at all; goals:review surfaces it as unmeasured
 * instead of letting it sit silently at zero.
 *
 * WHAT PRESSURE MAY AND MAY NOT DO
 * --------------------------------
 * Pressure actuates DISTRIBUTION and MIX — which platform, which objective,
 * which hour, which format. It must never actuate CLAIMS (no licence to
 * exaggerate, fabricate, or bypass compliance), and never volume beyond plan
 * caps. "Be more aggressive" is precisely the instruction that produced the July
 * fabrication incident; the ladder below is deliberately built out of levers
 * that cannot express a falsehood.
 *
 * Pure functions — no I/O, no clock. Callers pass the numbers in.
 */
final class GrowthPressure
{
    /** Below L1 nothing happens at all — the goal is on or near pace. */
    public const RUNG_NONE = 0;

    /** L1 — prompt bias. Tell the Strategist, with magnitude and streak. */
    public const RUNG_PROMPT = 1;

    /** L2 — mechanical mix skew in PHP. Stop asking the model nicely. */
    public const RUNG_MIX = 2;

    /** L3 — schedule + format actuation. Touches the publish path. */
    public const RUNG_SCHEDULE = 3;

    /** L4 — distribution actuation (interaction/community). No actuator yet. */
    public const RUNG_DISTRIBUTION = 4;

    public const THRESHOLD_PROMPT = 0.10;
    public const THRESHOLD_MIX = 0.25;
    public const THRESHOLD_SCHEDULE = 0.50;
    public const THRESHOLD_DISTRIBUTION = 0.75;

    /** Streak stops adding force after this many consecutive lagging reads. */
    public const STREAK_CAP = 5;

    /** Max multiplier contributed by a maxed-out streak (1.0 + this). */
    public const STREAK_MAX_BOOST = 0.5;

    /** Deadline pressure ramps in over the final N days of the window. */
    public const URGENCY_HORIZON_DAYS = 60;

    /** Max multiplier contributed by an imminent deadline (1.0 + this). */
    public const URGENCY_MAX_BOOST = 0.5;

    /**
     * Compute pressure for one goal.
     *
     * @param  float|null  $progressPct    REAL progress reading, or null if none
     * @param  float|null  $expectedPct    linear time-elapsed expectation
     * @param  int         $laggingStreak  consecutive lagging evaluations so far
     * @param  int|null    $daysRemaining  days until the window closes
     * @return float|null  [0,1], or null when there is no real reading
     */
    public static function score(
        ?float $progressPct,
        ?float $expectedPct,
        int $laggingStreak = 0,
        ?int $daysRemaining = null,
    ): ?float {
        // No reading → no pressure. Never infer urgency from ignorance.
        if ($progressPct === null || $expectedPct === null) {
            return null;
        }

        $gap = $expectedPct - $progressPct;
        if ($gap <= 0.0) {
            return 0.0; // on pace or ahead
        }

        $base = min(1.0, $gap / 100);

        return min(1.0, $base * self::urgencyMultiplier($daysRemaining) * self::streakMultiplier($laggingStreak));
    }

    /**
     * Deadline proximity. Flat 1.0 while the deadline is far away, ramping
     * linearly to 1.0+URGENCY_MAX_BOOST as it arrives. A window that has already
     * closed gets the full boost — the goal is out of time, not out of scope.
     */
    public static function urgencyMultiplier(?int $daysRemaining): float
    {
        if ($daysRemaining === null) {
            return 1.0;
        }
        $remaining = max(0, $daysRemaining);
        if ($remaining >= self::URGENCY_HORIZON_DAYS) {
            return 1.0;
        }

        $closeness = (self::URGENCY_HORIZON_DAYS - $remaining) / self::URGENCY_HORIZON_DAYS;

        return 1.0 + self::URGENCY_MAX_BOOST * $closeness;
    }

    /**
     * Persistence. Each consecutive lagging evaluation adds force, capped so a
     * long-stale goal can't dominate every other signal forever.
     */
    public static function streakMultiplier(int $laggingStreak): float
    {
        $streak = max(0, min(self::STREAK_CAP, $laggingStreak));

        return 1.0 + (self::STREAK_MAX_BOOST * $streak / self::STREAK_CAP);
    }

    /** Which actuator rung this pressure unlocks. Null pressure → RUNG_NONE. */
    public static function rung(?float $pressure): int
    {
        if ($pressure === null) {
            return self::RUNG_NONE;
        }
        if ($pressure >= self::THRESHOLD_DISTRIBUTION) {
            return self::RUNG_DISTRIBUTION;
        }
        if ($pressure >= self::THRESHOLD_SCHEDULE) {
            return self::RUNG_SCHEDULE;
        }
        if ($pressure >= self::THRESHOLD_MIX) {
            return self::RUNG_MIX;
        }
        if ($pressure >= self::THRESHOLD_PROMPT) {
            return self::RUNG_PROMPT;
        }

        return self::RUNG_NONE;
    }

    /** Human label for logs, the dashboard, and goals:review. */
    public static function rungLabel(int $rung): string
    {
        return match ($rung) {
            self::RUNG_PROMPT => 'L1 prompt-bias',
            self::RUNG_MIX => 'L2 mix-skew',
            self::RUNG_SCHEDULE => 'L3 schedule+format',
            self::RUNG_DISTRIBUTION => 'L4 distribution',
            default => 'L0 none',
        };
    }

    /**
     * The objective whose content most directly moves a target metric. Used to
     * skew the objective mix (L2) toward the thing the goal actually needs.
     * Returns null for metrics with no clean objective mapping.
     */
    public static function objectiveForMetric(string $metric): ?string
    {
        return match ($metric) {
            'followers' => 'awareness',
            'reach' => 'awareness',
            'engagement_rate' => 'engagement',
            'link_clicks' => 'traffic',
            'profile_visits' => 'traffic',
            default => null,
        };
    }
}
