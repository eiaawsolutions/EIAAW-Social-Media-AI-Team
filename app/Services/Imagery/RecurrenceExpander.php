<?php

namespace App\Services\Imagery;

use Illuminate\Support\Carbon;

/**
 * Pure, DB-free occurrence-list generator for a recurring customised post.
 *
 * Takes the FIRST publish instant + a recurrence rule and returns every
 * publish instant in the series, in ascending order. The first instant is
 * ALWAYS the head of the list (a "weekly on Mon+Wed" rule anchored to a
 * Tuesday still publishes that Tuesday, then Mon/Wed thereafter — the operator
 * pinned that first date deliberately).
 *
 * This is intentionally the only place the recurrence maths lives, so the
 * CustomisedPostScheduler just iterates a list it is handed and the rules are
 * unit-testable without a database (local .env DB == prod — keep tests DB-free).
 *
 * The downstream rail is unchanged: each returned instant becomes one
 * CalendarEntry + per-platform Draft set, exactly like a single customised
 * post. Recurrence is "materialise N occurrences up front through the existing
 * rail", NOT a new recurring cron — see CustomisedPostScheduler's class docblock.
 */
class RecurrenceExpander
{
    /** Recurrence frequencies the UI offers. 'none' = a single post (no series). */
    public const FREQ_NONE = 'none';
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_YEARLY = 'yearly';

    /** @var list<string> */
    public const FREQUENCIES = [
        self::FREQ_NONE,
        self::FREQ_DAILY,
        self::FREQ_WEEKLY,
        self::FREQ_MONTHLY,
        self::FREQ_YEARLY,
    ];

    /**
     * Hard ceiling on occurrences materialised from one upload — a safety rail
     * so a runaway "daily until 2030" can't flood the calendar with thousands of
     * drafts (and the publish-time plan cap then self-throttles whatever lands in
     * a capped month to queued_next_period). Tuned to one year of weekly posts.
     */
    public const MAX_OCCURRENCES = 52;

    /**
     * Expand a recurrence rule into the full ordered list of publish instants.
     *
     * @param  Carbon  $first       the first publish instant (operator-pinned), timezone-aware
     * @param  string  $frequency   one of FREQUENCIES
     * @param  int     $interval    every N units (>=1): e.g. interval=2 weekly = fortnightly
     * @param  list<int>|null  $weekdays  for weekly only: ISO days (1=Mon..7=Sun) the series fires on.
     *                                     Empty/null => use the first instant's own weekday.
     * @param  Carbon|null  $until   inclusive end date — stop once an occurrence would pass it
     * @param  int|null     $count   total occurrences to produce (includes the first), 1..MAX
     *
     * Exactly one bound should be supplied (until OR count); if both are given,
     * whichever stops the series first wins. If neither is given we still cap at
     * MAX_OCCURRENCES so the series is always finite.
     *
     * @return list<Carbon>  ascending, deduped, always >= 1 element (the first)
     */
    public function expand(
        Carbon $first,
        string $frequency,
        int $interval = 1,
        ?array $weekdays = null,
        ?Carbon $until = null,
        ?int $count = null,
    ): array {
        $first = $first->copy();

        // A non-recurring post is just the single pinned instant.
        if ($frequency === self::FREQ_NONE || ! in_array($frequency, self::FREQUENCIES, true)) {
            return [$first];
        }

        $interval = max(1, $interval);
        $cap = $this->resolveCap($count);
        $untilEnd = $until?->copy()->endOfDay(); // inclusive of the whole end day

        return match ($frequency) {
            self::FREQ_WEEKLY => $this->expandWeekly($first, $interval, $weekdays, $untilEnd, $cap),
            self::FREQ_DAILY => $this->expandStep($first, $cap, $untilEnd, fn (Carbon $c) => $c->addDays($interval)),
            self::FREQ_MONTHLY => $this->expandStep($first, $cap, $untilEnd, fn (Carbon $c) => $c->addMonthsNoOverflow($interval)),
            self::FREQ_YEARLY => $this->expandStep($first, $cap, $untilEnd, fn (Carbon $c) => $c->addYearsNoOverflow($interval)),
            default => [$first],
        };
    }

    /**
     * Clamp a requested occurrence count into [1, MAX_OCCURRENCES]. A null/<=0
     * count means "no count bound given" → fall back to the hard cap (the `until`
     * date or the cap itself then ends the series).
     */
    private function resolveCap(?int $count): int
    {
        if ($count === null || $count <= 0) {
            return self::MAX_OCCURRENCES;
        }

        return min($count, self::MAX_OCCURRENCES);
    }

    /**
     * Daily / monthly / yearly: walk forward by a fixed step until we hit the
     * count cap or pass the `until` date. The first instant is always included.
     *
     * @param  callable(Carbon):Carbon  $step  advances a (cloned) cursor in place
     * @return list<Carbon>
     */
    private function expandStep(Carbon $first, int $cap, ?Carbon $untilEnd, callable $step): array
    {
        $out = [$first->copy()];
        $cursor = $first->copy();

        while (count($out) < $cap) {
            $step($cursor); // Carbon mutates in place
            if ($untilEnd && $cursor->greaterThan($untilEnd)) {
                break;
            }
            $out[] = $cursor->copy();
        }

        return $out;
    }

    /**
     * Weekly with optional multi-weekday selection (Google-Calendar style:
     * "weekly on Mon + Wed"). The first instant always leads. After it, we scan
     * day by day; on each selected weekday we emit an occurrence at the first
     * instant's time-of-day. `interval` skips whole weeks — interval=2 with
     * Mon+Wed yields Mon/Wed this week, then nothing for a week, then Mon/Wed.
     *
     * @param  list<int>|null  $weekdays  ISO 1=Mon..7=Sun; empty/null => first's weekday
     * @return list<Carbon>
     */
    private function expandWeekly(Carbon $first, int $interval, ?array $weekdays, ?Carbon $untilEnd, int $cap): array
    {
        $days = $this->normaliseWeekdays($weekdays);
        if ($days === []) {
            // No explicit weekdays → behave like "every N weeks on the first
            // instant's own weekday".
            $days = [$this->isoDow($first)];
        }

        $out = [$first->copy()];

        // The Monday that starts the FIRST instant's week is our interval anchor:
        // a week is "in the series" when (weeks since anchor) % interval === 0.
        $anchorMonday = $first->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        // Scan forward from the day AFTER the first instant.
        $cursor = $first->copy()->addDay()->startOfDay();
        $h = (int) $first->format('H');
        $m = (int) $first->format('i');
        $s = (int) $first->format('s');

        // Bound the scan so a pathological rule can't loop forever: at most
        // MAX_OCCURRENCES weeks of look-ahead is plenty for a 52-cap series.
        $scanLimitDays = (self::MAX_OCCURRENCES + 1) * 7 * max(1, $interval);
        $scanned = 0;

        while (count($out) < $cap && $scanned < $scanLimitDays) {
            $scanned++;

            if ($untilEnd && $cursor->greaterThan($untilEnd)) {
                break;
            }

            $weeksSinceAnchor = (int) floor(
                $anchorMonday->diffInWeeks($cursor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay())
            );

            if ($weeksSinceAnchor % $interval === 0 && in_array($this->isoDow($cursor), $days, true)) {
                $out[] = $cursor->copy()->setTime($h, $m, $s);
            }

            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Sanitise an incoming weekday list to unique, sorted ISO day numbers in
     * 1..7. Anything out of range is dropped. Public + static so the form layer
     * and tests share one definition of "valid weekday set".
     *
     * @param  list<int|string>|null  $weekdays
     * @return list<int>
     */
    public function normaliseWeekdays(?array $weekdays): array
    {
        if (! is_array($weekdays)) {
            return [];
        }

        $clean = [];
        foreach ($weekdays as $d) {
            $n = (int) $d;
            if ($n >= 1 && $n <= 7) {
                $clean[$n] = $n; // dedupe via key
            }
        }
        ksort($clean);

        return array_values($clean);
    }

    /** ISO day-of-week: 1=Mon .. 7=Sun (Carbon's dayOfWeekIso). */
    private function isoDow(Carbon $c): int
    {
        return (int) $c->dayOfWeekIso;
    }
}
