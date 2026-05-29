<?php

namespace Tests\Unit;

use App\Services\Imagery\FalAiClient;
use App\Services\Imagery\ImageCreativeDirection;
use Tests\TestCase;

/**
 * Guards the model-family routing introduced when the default image model
 * switched from flux-pro to Nano Banana. The trap: Nano Banana takes
 * `aspect_ratio` (1:1/9:16/16:9), NOT flux's named `image_size` presets — so
 * size handling must be model-aware or vertical posters silently render square.
 */
class FalImageModelRoutingTest extends TestCase
{
    public function test_gemini_family_models_use_aspect_ratio(): void
    {
        $this->assertTrue(FalAiClient::modelUsesAspectRatio('fal-ai/nano-banana'));
        $this->assertTrue(FalAiClient::modelUsesAspectRatio('fal-ai/nano-banana/edit'));
        $this->assertTrue(FalAiClient::modelUsesAspectRatio('fal-ai/imagen4/preview'));
    }

    public function test_flux_family_models_use_image_size(): void
    {
        $this->assertFalse(FalAiClient::modelUsesAspectRatio('fal-ai/flux-pro/v1.1'));
        $this->assertFalse(FalAiClient::modelUsesAspectRatio('fal-ai/flux/schnell'));
        $this->assertFalse(FalAiClient::modelUsesAspectRatio('fal-ai/recraft-v3'));
    }

    public function test_image_size_presets_map_to_correct_aspect_ratio(): void
    {
        // The per-platform sizing intent must survive the model-family swap:
        // a vertical TikTok poster stays 9:16, not 1:1.
        $this->assertSame('9:16', FalAiClient::imageSizeToAspectRatio('portrait_16_9'));
        $this->assertSame('16:9', FalAiClient::imageSizeToAspectRatio('landscape_16_9'));
        $this->assertSame('1:1', FalAiClient::imageSizeToAspectRatio('square_hd'));
        // Unknown preset degrades to square, not an invalid value.
        $this->assertSame('1:1', FalAiClient::imageSizeToAspectRatio('something_weird'));
    }

    public function test_platform_sizing_end_to_end_for_nano_banana(): void
    {
        // The exact chain DesignerAgent uses: platform -> image_size preset ->
        // aspect_ratio for a Gemini-family model.
        $tiktok = FalAiClient::imageSizeToAspectRatio(FalAiClient::imageSizeForPlatform('tiktok'));
        $youtube = FalAiClient::imageSizeToAspectRatio(FalAiClient::imageSizeForPlatform('youtube'));
        $instagram = FalAiClient::imageSizeToAspectRatio(FalAiClient::imageSizeForPlatform('instagram'));

        $this->assertSame('9:16', $tiktok);
        $this->assertSame('16:9', $youtube);
        $this->assertSame('1:1', $instagram);
    }

    public function test_no_text_reinforcement_only_for_text_eager_models(): void
    {
        // Nano Banana renders text readily — must get the firmer no-text clause.
        $this->assertNotSame('', ImageCreativeDirection::noTextReinforcementFor('fal-ai/nano-banana'));
        $this->assertStringContainsStringIgnoringCase(
            'zero text',
            ImageCreativeDirection::noTextReinforcementFor('fal-ai/nano-banana'),
        );

        // flux doesn't need it — keep the prompt lean.
        $this->assertSame('', ImageCreativeDirection::noTextReinforcementFor('fal-ai/flux-pro/v1.1'));
    }
}
