<?php

namespace Tests\Unit;

use App\Agents\Prompts\StrategistPrompt;
use Tests\TestCase;

/**
 * P2 fix — the Growth-strategy section told the Strategist to "Set each entry's
 * scheduled_time intent ...", but the entry schema has NO scheduled_time field,
 * so the instruction is unrecoverable: the model can't emit it. Best posting
 * times are applied downstream by the auto-scheduler. Reworded to plan platform
 * + objective mix toward the best-time signal and defer the actual time to the
 * scheduler. DB-free (pure prompt + schema).
 */
class StrategistScheduledTimeDriftTest extends TestCase
{
    public function test_version_bumped(): void
    {
        // Tracks the current prompt version (v1.8 introduced the scheduled_time
        // drift fix asserted below; v1.9 added the director/platform-mechanics
        // upgrade). The scheduled_time invariants in this file still hold.
        $this->assertSame('strategist.v1.9', StrategistPrompt::VERSION);
    }

    public function test_schema_has_no_scheduled_time_field(): void
    {
        // Confirms the premise: the entry schema never carried scheduled_time.
        $props = StrategistPrompt::schema()['properties']['entries']['items']['properties'];
        $this->assertArrayNotHasKey('scheduled_time', $props);
    }

    public function test_prompt_no_longer_instructs_emitting_scheduled_time(): void
    {
        $system = StrategistPrompt::system();
        // The model must not be told to "Set each entry's scheduled_time" — that
        // field is not emittable. The growth section should still reference best
        // posting times (now deferred to the scheduler).
        $this->assertStringNotContainsString("Set each entry's scheduled_time", $system);
        $this->assertStringContainsStringIgnoringCase('best posting times', $system);
    }
}
