<?php

namespace Tests\Unit;

use App\Filament\Agency\Resources\Drafts\DraftResource;
use App\Models\Draft;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The "Generate video" confirmation copy must describe what the action will
 * actually DO for the draft's current state — including the case that caused
 * the "nothing happened" report: re-clicking on a draft that ALREADY has a
 * video. That branch tells the operator a fresh clip will be generated (the
 * action clears asset_url so VideoAgent doesn't idempotently no-op). Pure
 * private static → exercised via reflection, no DB.
 */
class GenerateVideoModalDescriptionTest extends TestCase
{
    private function describe(Draft $draft): string
    {
        $m = new ReflectionMethod(DraftResource::class, 'generateVideoModalDescription');
        $m->setAccessible(true);

        return $m->invoke(null, $draft);
    }

    private function draft(array $attrs): Draft
    {
        $draft = new Draft(['body' => 'A caption about reliability.']);
        foreach (['asset_url', 'branding_payload'] as $col) {
            if (array_key_exists($col, $attrs)) {
                $draft->setAttribute($col, $attrs[$col]);
            }
        }

        return $draft;
    }

    public function test_no_asset_describes_a_first_generation(): void
    {
        $copy = $this->describe($this->draft(['asset_url' => null]));

        $this->assertStringContainsStringIgnoringCase('from the current caption', $copy);
    }

    public function test_stale_still_describes_regenerating_the_image_first(): void
    {
        // Still exists but was made from a different body → stale.
        $draft = $this->draft([
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody('a different, older caption')],
        ]);

        $copy = $this->describe($draft);

        $this->assertStringContainsStringIgnoringCase('caption changed', $copy);
        $this->assertStringContainsStringIgnoringCase('regenerate the image', $copy);
    }

    public function test_existing_video_describes_a_fresh_clip(): void
    {
        // Fresh still-from-body, but asset_url is a video → re-click case.
        $draft = $this->draft([
            'asset_url' => 'https://cdn.example/clip.mp4',
            'branding_payload' => ['media_body_hash' => Draft::hashBody('A caption about reliability.')],
        ]);

        $copy = $this->describe($draft);

        $this->assertStringContainsStringIgnoringCase('already has a video', $copy);
        $this->assertStringContainsStringIgnoringCase('fresh', $copy);
    }

    public function test_current_image_keyframe_describes_animating_the_still(): void
    {
        $draft = $this->draft([
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody('A caption about reliability.')],
        ]);

        $copy = $this->describe($draft);

        $this->assertStringContainsStringIgnoringCase('keyframe', $copy);
        $this->assertStringNotContainsStringIgnoringCase('already has a video', $copy);
    }
}
