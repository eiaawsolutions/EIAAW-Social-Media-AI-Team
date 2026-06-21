<?php

namespace Tests\Unit;

use App\Services\Imagery\RecurrenceExpander;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Behavioural unit coverage for the recurrence occurrence-list generator. This
 * is pure Carbon maths with no DB touch, so unlike the scheduler's source-level
 * guards these are real assertions on real output (local .env DB == prod — keep
 * tests DB-free; this class genuinely is).
 */
class RecurrenceExpanderTest extends TestCase
{
    private function exp(): RecurrenceExpander
    {
        return new RecurrenceExpander();
    }

    private function at(string $iso, string $tz = 'Asia/Kuala_Lumpur'): Carbon
    {
        return Carbon::parse($iso, $tz);
    }

    /** @param list<Carbon> $list */
    private function isoDates(array $list): array
    {
        return array_map(fn (Carbon $c) => $c->toDateString(), $list);
    }

    public function test_none_returns_single_occurrence(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_NONE);
        $this->assertCount(1, $out);
        $this->assertSame('2026-06-21 16:00:00', $out[0]->format('Y-m-d H:i:s'));
    }

    public function test_unknown_frequency_falls_back_to_single(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), 'fortnightly-ish');
        $this->assertCount(1, $out);
    }

    public function test_daily_count_includes_first_and_runs_n_days(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_DAILY, count: 5);
        $this->assertSame(
            ['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25'],
            $this->isoDates($out),
        );
        // Time-of-day is preserved on every occurrence.
        foreach ($out as $c) {
            $this->assertSame('16:00:00', $c->format('H:i:s'));
        }
    }

    public function test_daily_with_interval_steps_by_n_days(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 09:30'), RecurrenceExpander::FREQ_DAILY, interval: 3, count: 4);
        $this->assertSame(
            ['2026-06-21', '2026-06-24', '2026-06-27', '2026-06-30'],
            $this->isoDates($out),
        );
    }

    public function test_daily_until_date_is_inclusive_of_the_whole_end_day(): void
    {
        // until = 24th: an occurrence anywhere on the 24th must be included even
        // though its time (16:00) is after midnight — endOfDay() inclusivity.
        $out = $this->exp()->expand(
            $this->at('2026-06-21 16:00'),
            RecurrenceExpander::FREQ_DAILY,
            until: $this->at('2026-06-24 00:00'),
        );
        $this->assertSame(
            ['2026-06-21', '2026-06-22', '2026-06-23', '2026-06-24'],
            $this->isoDates($out),
        );
    }

    public function test_weekly_default_uses_first_instants_weekday(): void
    {
        // 2026-06-21 is a Sunday. Weekly with no explicit weekdays => every Sunday.
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_WEEKLY, count: 3);
        $this->assertSame(['2026-06-21', '2026-06-28', '2026-07-05'], $this->isoDates($out));
    }

    public function test_weekly_multi_weekday_emits_each_selected_day(): void
    {
        // First instant Mon 2026-06-22 10:00; series on Mon(1) + Wed(3).
        // Expect: Mon 22, Wed 24, Mon 29, Wed 01-Jul, ...
        $out = $this->exp()->expand(
            $this->at('2026-06-22 10:00'),
            RecurrenceExpander::FREQ_WEEKLY,
            weekdays: [1, 3],
            count: 4,
        );
        $this->assertSame(
            ['2026-06-22', '2026-06-24', '2026-06-29', '2026-07-01'],
            $this->isoDates($out),
        );
    }

    public function test_weekly_first_instant_leads_even_if_not_in_weekday_set(): void
    {
        // First instant is a Tuesday (23rd) but the rule is Mon+Wed. The pinned
        // first date must still publish, then the series follows Mon/Wed.
        $out = $this->exp()->expand(
            $this->at('2026-06-23 14:00'),
            RecurrenceExpander::FREQ_WEEKLY,
            weekdays: [1, 3],
            count: 4,
        );
        $this->assertSame('2026-06-23', $out[0]->toDateString(), 'pinned first date must lead');
        $this->assertSame(
            ['2026-06-23', '2026-06-24', '2026-06-29', '2026-07-01'],
            $this->isoDates($out),
        );
    }

    public function test_weekly_interval_two_skips_a_week(): void
    {
        // Fortnightly on the first instant's weekday (Sunday 21st).
        $out = $this->exp()->expand(
            $this->at('2026-06-21 16:00'),
            RecurrenceExpander::FREQ_WEEKLY,
            interval: 2,
            count: 3,
        );
        $this->assertSame(['2026-06-21', '2026-07-05', '2026-07-19'], $this->isoDates($out));
    }

    public function test_monthly_no_overflow_clamps_end_of_month(): void
    {
        // Jan 31 + 1 month must land on Feb 28 (no overflow into March), then
        // Mar 28, etc. — addMonthsNoOverflow semantics.
        $out = $this->exp()->expand($this->at('2026-01-31 08:00'), RecurrenceExpander::FREQ_MONTHLY, count: 3);
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-28'], $this->isoDates($out));
    }

    public function test_yearly_steps_by_year(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_YEARLY, count: 3);
        $this->assertSame(['2026-06-21', '2027-06-21', '2028-06-21'], $this->isoDates($out));
    }

    public function test_count_is_capped_at_max_occurrences(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_DAILY, count: 9999);
        $this->assertCount(RecurrenceExpander::MAX_OCCURRENCES, $out);
    }

    public function test_no_bound_still_finite_at_cap(): void
    {
        // Neither until nor count → never-ending in intent, but the hard cap keeps
        // it finite so we never flood the calendar.
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_DAILY);
        $this->assertCount(RecurrenceExpander::MAX_OCCURRENCES, $out);
    }

    public function test_until_in_the_past_yields_only_the_first(): void
    {
        $out = $this->exp()->expand(
            $this->at('2026-06-21 16:00'),
            RecurrenceExpander::FREQ_DAILY,
            until: $this->at('2026-06-20 00:00'),
        );
        $this->assertCount(1, $out, 'an until before the first instant means just the pinned post');
        $this->assertSame('2026-06-21', $out[0]->toDateString());
    }

    public function test_count_one_yields_only_the_first(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_WEEKLY, count: 1);
        $this->assertCount(1, $out);
    }

    public function test_interval_below_one_is_treated_as_one(): void
    {
        $out = $this->exp()->expand($this->at('2026-06-21 16:00'), RecurrenceExpander::FREQ_DAILY, interval: 0, count: 3);
        $this->assertSame(['2026-06-21', '2026-06-22', '2026-06-23'], $this->isoDates($out));
    }

    public function test_normalise_weekdays_dedupes_sorts_and_drops_out_of_range(): void
    {
        $this->assertSame([1, 3, 5], $this->exp()->normaliseWeekdays([5, 1, 3, 1, 8, 0, '3']));
        $this->assertSame([], $this->exp()->normaliseWeekdays(null));
        $this->assertSame([], $this->exp()->normaliseWeekdays([0, 9, -1]));
    }

    public function test_occurrences_are_strictly_ascending(): void
    {
        $out = $this->exp()->expand(
            $this->at('2026-06-22 10:00'),
            RecurrenceExpander::FREQ_WEEKLY,
            weekdays: [1, 3, 5],
            count: 10,
        );
        for ($i = 1; $i < count($out); $i++) {
            $this->assertTrue(
                $out[$i]->greaterThan($out[$i - 1]),
                "occurrence {$i} must be strictly after occurrence " . ($i - 1),
            );
        }
    }
}
