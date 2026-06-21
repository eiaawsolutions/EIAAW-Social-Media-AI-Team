<?php

namespace App\Services\Imagery;

use Illuminate\Support\Carbon;

/**
 * Immutable recurrence rule passed from the upload form into
 * CustomisedPostScheduler. A null Recurrence (or one with frequency 'none')
 * means a single post — the scheduler's original behaviour, unchanged.
 *
 * Kept as a tiny readonly value object (not an array) so the scheduler signature
 * stays self-documenting and the normalisation lives in one place. DB-free.
 */
final class Recurrence
{
    /**
     * @param  string  $frequency  one of RecurrenceExpander::FREQUENCIES
     * @param  int     $interval   every N units (>=1)
     * @param  list<int>  $weekdays  weekly only: ISO days 1=Mon..7=Sun
     * @param  Carbon|null  $until   inclusive end date (mutually-best with count)
     * @param  int|null     $count   total occurrences incl. the first (1..MAX)
     */
    public function __construct(
        public readonly string $frequency,
        public readonly int $interval = 1,
        public readonly array $weekdays = [],
        public readonly ?Carbon $until = null,
        public readonly ?int $count = null,
    ) {}

    /** True when this rule actually repeats (i.e. is not a single post). */
    public function repeats(): bool
    {
        return $this->frequency !== RecurrenceExpander::FREQ_NONE
            && in_array($this->frequency, RecurrenceExpander::FREQUENCIES, true);
    }

    /**
     * Build from the raw Filament form payload, defaulting safely. Anything the
     * operator left blank degrades to a single post.
     *
     * @param  array<string,mixed>  $data   the upload form's submitted array
     * @param  string  $tz   brand timezone, for interpreting the `until` date
     */
    public static function fromFormData(array $data, string $tz): self
    {
        $frequency = (string) ($data['recurrence_frequency'] ?? RecurrenceExpander::FREQ_NONE);
        if (! in_array($frequency, RecurrenceExpander::FREQUENCIES, true)) {
            $frequency = RecurrenceExpander::FREQ_NONE;
        }

        $interval = max(1, (int) ($data['recurrence_interval'] ?? 1));

        $weekdays = (new RecurrenceExpander())->normaliseWeekdays(
            is_array($data['recurrence_weekdays'] ?? null) ? $data['recurrence_weekdays'] : [],
        );

        // The form's "Ends" radio picks ONE bound; the other stays null.
        $endsMode = (string) ($data['recurrence_ends'] ?? 'count');
        $count = null;
        $until = null;
        if ($endsMode === 'count') {
            $count = max(1, (int) ($data['recurrence_count'] ?? 1));
        } elseif ($endsMode === 'until' && ! empty($data['recurrence_until'])) {
            try {
                $until = Carbon::parse((string) $data['recurrence_until'], $tz);
            } catch (\Throwable) {
                $until = null;
            }
        }

        return new self($frequency, $interval, $weekdays, $until, $count);
    }
}
