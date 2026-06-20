<?php

namespace Tests\Unit;

use App\Filament\Agency\Pages\DraftEditor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The editor strips Writer-derived copy fields from platform_payload when the
 * caption changes, so an edited caption can't drag a stale headline / CTA /
 * carousel arc into the next image generation. Exercised directly via
 * reflection (the helper is a pure private static — no DB, no Livewire boot).
 */
class DraftEditorPayloadStripTest extends TestCase
{
    private function strip(mixed $payload): ?array
    {
        $m = new ReflectionMethod(DraftEditor::class, 'stripDerivedCopyFields');
        $m->setAccessible(true);

        return $m->invoke(null, $payload);
    }

    public function test_null_payload_stays_null(): void
    {
        $this->assertNull($this->strip(null));
    }

    public function test_derived_copy_fields_are_removed(): void
    {
        $result = $this->strip([
            'headline' => 'Stale hook',
            'cta' => 'Stale CTA',
            'carousel_slides' => [['title' => 'Slide 1']],
            'hook_pattern' => 'question',
        ]);

        $this->assertNull($result, 'a payload of only derived fields collapses to null');
    }

    public function test_non_derived_keys_are_preserved(): void
    {
        $result = $this->strip([
            'headline' => 'Stale hook',
            'cta' => 'Stale CTA',
            'some_future_field' => 'keep me',
        ]);

        $this->assertSame(['some_future_field' => 'keep me'], $result);
        $this->assertArrayNotHasKey('headline', $result);
        $this->assertArrayNotHasKey('cta', $result);
    }

    public function test_empty_array_collapses_to_null(): void
    {
        $this->assertNull($this->strip([]));
    }
}
