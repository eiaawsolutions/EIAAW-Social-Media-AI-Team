<?php

namespace Tests\Unit;

use App\Services\Imagery\CustomisedPostScheduler;
use Tests\TestCase;

/**
 * DB-free unit coverage for the customised-post platform gate. The scheduler's
 * write path needs the (prod) DB, so we only assert the pure logic here, in
 * line with the project's "keep tests DB-free" constraint.
 */
class CustomisedPostSchedulerTest extends TestCase
{
    public function test_lowercases_and_dedupes_platforms(): void
    {
        $this->assertSame(
            ['instagram', 'facebook'],
            CustomisedPostScheduler::normalisePlatforms(['Instagram', ' FACEBOOK ', 'instagram']),
        );
    }

    public function test_drops_unsupported_platforms(): void
    {
        $this->assertSame(
            ['linkedin'],
            CustomisedPostScheduler::normalisePlatforms(['linkedin', 'myspace', 'snapchat', '']),
        );
    }

    public function test_empty_when_nothing_valid(): void
    {
        $this->assertSame([], CustomisedPostScheduler::normalisePlatforms(['bebo', 'orkut']));
    }

    public function test_all_supported_platforms_pass_through(): void
    {
        $all = CustomisedPostScheduler::SUPPORTED_PLATFORMS;
        $this->assertSame($all, CustomisedPostScheduler::normalisePlatforms($all));
    }

    public function test_x_is_supported_but_twitter_alias_is_not_silently_accepted(): void
    {
        // We store 'x' (modern brand); 'twitter' is mapped to 'x' only at the
        // Blotato boundary, not here — so a raw 'twitter' input is rejected to
        // avoid a platform the connection layer doesn't expect.
        $this->assertSame(['x'], CustomisedPostScheduler::normalisePlatforms(['x', 'twitter']));
    }

    /**
     * Regression guard for "Could not schedule the customised post —
     * SQLSTATE[23502] Not null violation: pillar_mix".
     *
     * content_calendars.pillar_mix and .format_mix are NOT NULL (the Strategist
     * agent fills them for generated calendars). The reusable "Customised posts"
     * calendar is created by the scheduler's firstOrCreate and carries no
     * pillar/format strategy — so the scheduler MUST supply non-null values for
     * every NOT-NULL *_mix column, or the INSERT 23502s.
     *
     * This test reads the NOT-NULL *_mix columns straight out of the migration
     * and asserts the scheduler's customisedCalendar() create block supplies each
     * one — so adding a new NOT-NULL *_mix column later fails here until the
     * scheduler is updated. DB-free by design (local .env DB == prod).
     */
    public function test_customised_calendar_supplies_every_not_null_mix_column(): void
    {
        $migration = (string) file_get_contents(
            base_path('database/migrations/2026_04_30_100400_create_content_tables.php'),
        );

        // Isolate the content_calendars Schema::create block.
        $this->assertSame(
            1,
            preg_match(
                "/Schema::create\('content_calendars'.*?\}\);/s",
                $migration,
                $block,
            ),
            'Could not locate the content_calendars schema block.',
        );
        $calendarSchema = $block[0];

        // A *_mix column is NOT NULL unless the same statement chains ->nullable().
        preg_match_all(
            "/->(?:json|jsonb)\('([a-z_]+_mix)'\)([^;]*);/",
            $calendarSchema,
            $mixCols,
            PREG_SET_ORDER,
        );
        $notNullMix = [];
        foreach ($mixCols as $m) {
            [$full, $name, $chain] = $m;
            if (! str_contains($chain, '->nullable(')) {
                $notNullMix[] = $name;
            }
        }

        // Sanity: the migration really does have NOT-NULL *_mix columns to guard.
        $this->assertContains('pillar_mix', $notNullMix, 'Expected pillar_mix to be NOT NULL in the migration.');

        // The scheduler's customised-calendar create block must supply each one.
        $scheduler = (string) file_get_contents(
            app_path('Services/Imagery/CustomisedPostScheduler.php'),
        );
        $this->assertSame(
            1,
            preg_match('/function customisedCalendar\(.*?\n    \}/s', $scheduler, $fn),
            'Could not locate customisedCalendar() in the scheduler.',
        );
        $createBlock = $fn[0];

        foreach ($notNullMix as $col) {
            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($col, '/') . "'\s*=>/",
                $createBlock,
                "customisedCalendar() must supply a non-null '{$col}' (it is NOT NULL in content_calendars) "
                . 'or the INSERT fails with SQLSTATE 23502.',
            );
        }
    }
}
