<?php

namespace Tests\Unit;

use App\Services\Imagery\ImageCreativeDirection;
use Tests\TestCase;

class ImageCreativeDirectionTest extends TestCase
{
    public function test_realism_block_carries_core_execution_signals(): void
    {
        $block = ImageCreativeDirection::realismBlock();

        // Lighting + lens + DoF — the signals that actually steer flux toward
        // a photographed look rather than a glossy render.
        $this->assertStringContainsStringIgnoringCase('natural daylight', $block);
        $this->assertStringContainsStringIgnoringCase('lens', $block);
        $this->assertStringContainsStringIgnoringCase('depth of field', $block);

        // Texture realism + natural imperfections.
        $this->assertStringContainsStringIgnoringCase('grain', $block);
        $this->assertStringContainsStringIgnoringCase('imperfection', $block);

        // Human anatomy guard (only relevant when people appear).
        $this->assertStringContainsStringIgnoringCase('five fingers', $block);
        $this->assertStringContainsStringIgnoringCase('anatomy', $block);
    }

    public function test_realism_block_folds_negatives_in_prompt_for_no_negative_field_models(): void
    {
        // flux-pro/v1.1 has no negative_prompt field, so the realism block
        // must itself say what to AVOID — otherwise the AI-tell signals are lost.
        $block = ImageCreativeDirection::realismBlock();

        $this->assertStringContainsStringIgnoringCase('AVOID', $block);
        $this->assertStringContainsStringIgnoringCase('plastic', $block);
        $this->assertStringContainsStringIgnoringCase('AI artifacts', $block);
    }

    public function test_negative_prompt_excludes_the_obvious_ai_tells(): void
    {
        $negative = ImageCreativeDirection::negativePrompt();

        foreach (['CGI', '3D render', 'plastic skin', 'bad hands', 'extra fingers', 'bad anatomy', 'watermark', 'text'] as $token) {
            $this->assertStringContainsString($token, $negative);
        }
    }
}
