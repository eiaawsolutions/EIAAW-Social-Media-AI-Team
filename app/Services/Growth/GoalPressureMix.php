<?php

namespace App\Services\Growth;

/**
 * L2 actuation: mechanically skew the month's platform / objective / format mix
 * toward goals that are behind pace.
 *
 * WHY MECHANICAL
 * --------------
 * The pre-existing L1 actuator asked the planning model, in prose, to
 * "over-index instagram in the platform split". Whether that happened at all was
 * unverifiable — it depended on the model honouring one paragraph buried in a
 * ~10-section prompt, and prod showed brand#1's Instagram goal sitting at 0%
 * against 47.4% expected for weeks with no observable change in the plan. L2
 * moves the weights in PHP before the prompt is built, so the shift is a fact
 * about the input rather than a hope about the output.
 *
 * BOUNDED BY CONSTRUCTION
 * -----------------------
 * Skew is a transfer, not an inflation: weight taken from donors equals weight
 * given to targets, and every surface keeps at least MIN_WEIGHT. This preserves
 * the invariant OptimizerAgent depends on — a platform never drops to zero,
 * because a surface with no posts can never prove it has recovered. The total
 * transferable share is capped at MAX_TOTAL_SKEW so a single lagging goal cannot
 * consume the whole month.
 *
 * Pure functions — no I/O. Every method returns a mix that sums to 1.0.
 */
final class GoalPressureMix
{
    /** Floor for any surface in the mix. Mirrors OptimizerAgent::MIN_PLATFORM_WEIGHT. */
    public const MIN_WEIGHT = 0.05;

    /** Ceiling on total weight transferable to goal platforms in one month. */
    public const MAX_TOTAL_SKEW = 0.35;

    /**
     * Skew a platform mix toward the platforms carrying goal pressure.
     *
     * Platforms absent from $mix are ignored: a goal on a platform this brand
     * does not publish to cannot be actuated here, and inventing a share for it
     * would plan posts to a surface with no active connection.
     *
     * @param  array<string,float>  $mix                platform => weight
     * @param  array<string,float>  $pressureByPlatform platform => pressure [0,1]
     * @return array<string,float>
     */
    public static function skewPlatformMix(array $mix, array $pressureByPlatform): array
    {
        return self::skew($mix, $pressureByPlatform, self::MIN_WEIGHT, self::MAX_TOTAL_SKEW);
    }

    /**
     * Skew an objective mix toward the objectives that drive the lagging metrics.
     * Callers map metric => objective via GrowthPressure::objectiveForMetric().
     *
     * @param  array<string,float>  $mix                 objective => weight
     * @param  array<string,float>  $pressureByObjective objective => pressure [0,1]
     * @return array<string,float>
     */
    public static function skewObjectiveMix(array $mix, array $pressureByObjective): array
    {
        return self::skew($mix, $pressureByObjective, self::MIN_WEIGHT, self::MAX_TOTAL_SKEW);
    }

    /**
     * L3: skew a format mix toward the formats that earn distribution rather
     * than just impressions — saves and shares are the algorithmic signals that
     * reach non-followers, which is the only thing that moves a follower goal.
     *
     * Deliberately narrower than the platform/objective skew: format changes
     * carry real COGS (a reel is an AI video generation, and videos have their
     * own plan cap), so the transferable budget is halved.
     *
     * @param  array<string,float>  $mix             format => weight
     * @param  array<string,float>  $pressureByFormat format => pressure [0,1]
     * @return array<string,float>
     */
    public static function skewFormatMix(array $mix, array $pressureByFormat): array
    {
        return self::skew($mix, $pressureByFormat, self::MIN_WEIGHT, self::MAX_TOTAL_SKEW / 2);
    }

    /**
     * The shared transfer. Takes weight from non-targeted keys down to (never
     * below) $floor and hands it to targeted keys in proportion to pressure.
     *
     * Degenerate cases all return a normalised copy of the input rather than
     * throwing: no targets, no donors, donors already at the floor, or an empty
     * mix. Refusing to act is always safe here — the prior mix is a valid plan.
     *
     * @param  array<string,float>  $mix
     * @param  array<string,float>  $pressure
     * @return array<string,float>
     */
    private static function skew(array $mix, array $pressure, float $floor, float $maxSkew): array
    {
        $mix = self::normalise($mix);
        if ($mix === []) {
            return [];
        }

        // Targets must exist in the mix and carry real pressure.
        $desired = [];
        foreach ($pressure as $key => $p) {
            $p = (float) $p;
            if ($p > 0.0 && array_key_exists($key, $mix)) {
                $desired[$key] = $maxSkew * min(1.0, $p);
            }
        }
        if ($desired === []) {
            return $mix;
        }

        // Cap the combined ask so several lagging goals can't take the whole month.
        $totalDesired = array_sum($desired);
        if ($totalDesired > $maxSkew) {
            $rescale = $maxSkew / $totalDesired;
            foreach ($desired as $k => $v) {
                $desired[$k] = $v * $rescale;
            }
            $totalDesired = $maxSkew;
        }

        // Donors are every non-target key, contributing only their headroom
        // above the floor.
        $headroom = [];
        foreach ($mix as $key => $weight) {
            if (! array_key_exists($key, $desired)) {
                $headroom[$key] = max(0.0, $weight - $floor);
            }
        }
        $available = array_sum($headroom);

        $transfer = min($totalDesired, $available);
        if ($transfer <= 0.0) {
            return $mix; // nothing to give — every donor is already at the floor
        }

        $out = $mix;
        foreach ($headroom as $key => $room) {
            if ($room > 0.0) {
                $out[$key] -= $room / $available * $transfer;
            }
        }
        foreach ($desired as $key => $want) {
            $out[$key] += $want / $totalDesired * $transfer;
        }

        return self::normalise($out);
    }

    /**
     * Drop non-positive weights, rescale to sum 1.0, round to 4dp (the precision
     * the rest of the mix pipeline uses).
     *
     * @param  array<string,float>  $mix
     * @return array<string,float>
     */
    public static function normalise(array $mix): array
    {
        $clean = [];
        foreach ($mix as $key => $weight) {
            $weight = (float) $weight;
            if ($weight > 0.0) {
                $clean[$key] = $weight;
            }
        }

        $sum = array_sum($clean);
        if ($sum <= 0.0) {
            return [];
        }

        $out = [];
        foreach ($clean as $key => $weight) {
            $out[$key] = round($weight / $sum, 4);
        }

        return $out;
    }
}
