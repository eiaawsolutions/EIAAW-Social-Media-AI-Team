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

    public function test_stills_block_does_not_use_banned_flux_terms(): void
    {
        // The Flux optimisation rules explicitly ban "photorealistic" and "4K"
        // — they push Flux toward the glossy AI look the contract fights.
        $block = ImageCreativeDirection::realismBlock();

        $this->assertStringNotContainsStringIgnoringCase('photorealistic', $block);
        $this->assertStringNotContainsString('4K', $block);
    }

    public function test_video_block_carries_kinetic_and_motion_signals(): void
    {
        $block = ImageCreativeDirection::videoRealismBlock();

        // Camera-motion vocabulary — the steering that makes Wan move like
        // real footage rather than an AI swirl.
        $this->assertStringContainsStringIgnoringCase('camera', $block);
        $this->assertStringContainsStringIgnoringCase('dolly', $block);
        $this->assertStringContainsStringIgnoringCase('handheld', $block);

        // Believable physics + pacing + anatomy-in-motion.
        $this->assertStringContainsStringIgnoringCase('momentum', $block);
        $this->assertStringContainsStringIgnoringCase('pacing', $block);
        $this->assertStringContainsStringIgnoringCase('anatomy', $block);

        // In-prompt negative reinforcement.
        $this->assertStringContainsStringIgnoringCase('AVOID', $block);
    }

    public function test_video_negative_prompt_targets_temporal_artifacts_and_fits_wan_limit(): void
    {
        $negative = ImageCreativeDirection::videoNegativePrompt();

        // Temporal artefacts unique to video.
        foreach (['morphing face', 'flickering', 'warping limbs', 'strobing'] as $token) {
            $this->assertStringContainsString($token, $negative);
        }

        // Wan 2.5/2.6 caps negative_prompt at 500 chars — must stay under.
        $this->assertLessThanOrEqual(500, strlen($negative));
    }
}
