<?php

namespace Tests\Unit;

use App\Services\Imagery\Recurrence;
use App\Services\Imagery\RecurrenceExpander;
use Tests\TestCase;

/**
 * Behavioural coverage for the Recurrence value object + its form-payload
 * factory. Pure logic, DB-free (local .env DB == prod).
 */
class RecurrenceTest extends TestCase
{
    public function test_none_does_not_repeat(): void
    {
        $r = new Recurrence(RecurrenceExpander::FREQ_NONE);
        $this->assertFalse($r->repeats());
    }

    public function test_weekly_repeats(): void
    {
        $r = new Recurrence(RecurrenceExpander::FREQ_WEEKLY);
        $this->assertTrue($r->repeats());
    }

    public function test_unknown_frequency_does_not_repeat(): void
    {
        $r = new Recurrence('whenever');
        $this->assertFalse($r->repeats());
    }

    public function test_from_form_defaults_to_a_single_post(): void
    {
        $r = Recurrence::fromFormData([], 'Asia/Kuala_Lumpur');
        $this->assertSame(RecurrenceExpander::FREQ_NONE, $r->frequency);
        $this->assertFalse($r->repeats());
    }

    public function test_from_form_reads_count_bound(): void
    {
        $r = Recurrence::fromFormData([
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 2,
            'recurrence_weekdays' => [3, 1, 1, 8], // dirty input
            'recurrence_ends' => 'count',
            'recurrence_count' => 6,
            'recurrence_until' => '2026-12-31', // must be ignored when ends=count
        ], 'Asia/Kuala_Lumpur');

        $this->assertSame('weekly', $r->frequency);
        $this->assertSame(2, $r->interval);
        $this->assertSame([1, 3], $r->weekdays, 'weekdays must be normalised (dedupe/sort/range)');
        $this->assertSame(6, $r->count);
        $this->assertNull($r->until, 'until must be null when the ends-mode is count');
    }

    public function test_from_form_reads_until_bound(): void
    {
        $r = Recurrence::fromFormData([
            'recurrence_frequency' => 'daily',
            'recurrence_ends' => 'until',
            'recurrence_until' => '2026-08-30',
            'recurrence_count' => 99, // must be ignored when ends=until
        ], 'Asia/Kuala_Lumpur');

        $this->assertSame('daily', $r->frequency);
        $this->assertNull($r->count, 'count must be null when the ends-mode is until');
        $this->assertNotNull($r->until);
        $this->assertSame('2026-08-30', $r->until->toDateString());
        $this->assertSame('Asia/Kuala_Lumpur', $r->until->getTimezone()->getName());
    }

    public function test_from_form_clamps_interval_and_count_to_at_least_one(): void
    {
        $r = Recurrence::fromFormData([
            'recurrence_frequency' => 'daily',
            'recurrence_interval' => 0,
            'recurrence_ends' => 'count',
            'recurrence_count' => 0,
        ], 'UTC');

        $this->assertSame(1, $r->interval);
        $this->assertSame(1, $r->count);
    }

    public function test_from_form_invalid_frequency_degrades_to_none(): void
    {
        $r = Recurrence::fromFormData(['recurrence_frequency' => 'hourly'], 'UTC');
        $this->assertSame(RecurrenceExpander::FREQ_NONE, $r->frequency);
    }

    public function test_from_form_bad_until_string_degrades_to_null(): void
    {
        $r = Recurrence::fromFormData([
            'recurrence_frequency' => 'daily',
            'recurrence_ends' => 'until',
            'recurrence_until' => 'not-a-date',
        ], 'UTC');

        $this->assertNull($r->until);
    }
}
