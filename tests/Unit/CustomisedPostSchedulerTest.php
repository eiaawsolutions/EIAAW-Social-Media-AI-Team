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
}
