<?php

namespace Tests\Unit;

use App\Agents\DesignerAgent;
use App\Models\Draft;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P2 hardening — hookSlideDirection() reads a model-supplied carousel slide's
 * visual_direction from draft.platform_payload and interpolates it into the FAL
 * prompt. An over-long / malformed value should not flow in unbounded. This locks
 * that the helper trims, tolerates a malformed payload shape, and bounds length.
 * DB-free (unsaved Draft + reflection).
 */
class DesignerHookSlideBoundTest extends TestCase
{
    private function designer(): DesignerAgent
    {
        return new DesignerAgent(new LlmGateway);
    }

    private function hookSlide(Draft $draft): string
    {
        $m = new ReflectionMethod(DesignerAgent::class, 'hookSlideDirection');

        return $m->invoke($this->designer(), $draft);
    }

    public function test_returns_first_slide_visual_direction_trimmed(): void
    {
        $draft = new Draft;
        $draft->platform_payload = ['carousel_slides' => [
            ['visual_direction' => '  a hero shot of the product  '],
        ]];

        $this->assertSame('a hero shot of the product', $this->hookSlide($draft));
    }

    public function test_empty_when_no_carousel_payload(): void
    {
        $this->assertSame('', $this->hookSlide(new Draft));

        $draft = new Draft;
        $draft->platform_payload = ['carousel_slides' => []];
        $this->assertSame('', $this->hookSlide($draft));
    }

    public function test_tolerates_malformed_first_slide(): void
    {
        $draft = new Draft;
        $draft->platform_payload = ['carousel_slides' => ['not-an-array']];
        $this->assertSame('', $this->hookSlide($draft));
    }

    public function test_bounds_overlong_visual_direction(): void
    {
        $draft = new Draft;
        $draft->platform_payload = ['carousel_slides' => [
            ['visual_direction' => str_repeat('x', 600)],
        ]];

        $out = $this->hookSlide($draft);
        $this->assertLessThanOrEqual(DesignerAgent::MAX_HOOK_SLIDE_CHARS, mb_strlen($out));
    }
}
