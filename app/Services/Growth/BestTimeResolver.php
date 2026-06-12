<?php

namespace App\Services\Growth;

/**
 * Resolves the best hour to post for a (platform, day-of-week) from a brand's
 * GrowthStrategyBrief.best_posting_times. Pure functions — no I/O, DB-light
 * testable.
 *
 * Used by the auto-scheduler ONLY as the fallback hour when the operator did not
 * pin a scheduled_time (today that fallback is a hardcoded 09:00). It NEVER
 * overrides an operator-set time, and it's gated behind
 * config('services.growth_strategy.auto_apply_best_times') — see
 * PostsAutoScheduleApproved.
 */
final class BestTimeResolver
{
    /**
     * Best hour (0–23) for a platform on a given day-of-week, or null when the
     * brief has no trustworthy slot for it (caller then keeps its own fallback).
     *
     * Selection: among the platform's computed buckets, prefer an exact
     * day-of-week match (highest avg_score wins); if none match the day, fall
     * back to the platform's overall best bucket (any day). Returns null if the
     * platform has no buckets at all.
     *
     * @param  array<string,mixed>  $bestPostingTimes  brief.best_posting_times: {platform: [{day_of_week,hour,avg_score,...}]}
     */
    public static function hourFor(array $bestPostingTimes, string $platform, int $dayOfWeek): ?int
    {
        $buckets = $bestPostingTimes[$platform] ?? null;
        if (! is_array($buckets) || $buckets === []) {
            return null;
        }

        $exactDay = [];
        $anyDay = [];
        foreach ($buckets as $b) {
            if (! is_array($b) || ! isset($b['hour'])) {
                continue;
            }
            $row = [
                'hour' => (int) $b['hour'],
                'score' => (float) ($b['avg_score'] ?? 0),
                'dow' => isset($b['day_of_week']) ? (int) $b['day_of_week'] : null,
            ];
            $anyDay[] = $row;
            if ($row['dow'] === $dayOfWeek) {
                $exactDay[] = $row;
            }
        }

        $pool = $exactDay !== [] ? $exactDay : $anyDay;
        if ($pool === []) {
            return null;
        }

        usort($pool, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $pool[0]['hour'];
    }
}
