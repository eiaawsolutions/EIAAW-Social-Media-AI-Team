<?php

namespace Tests\Unit;

use App\Services\Imagery\ImageCreativeDirection;
use Tests\TestCase;

/**
 * Guards the summary-poster routing + content composition. A poster is a
 * designed graphic whose headline + key points are rendered AS TEXT by a
 * text-capable model — the opposite of the default text-free photo path — so
 * the gate must be precise: only single-image educational/listicle/quote-card
 * formats, never lifestyle photos or video keyframes.
 */
class SummaryPosterTest extends TestCase
{
    public function test_educational_single_image_is_poster(): void
    {
        $this->assertTrue(ImageCreativeDirection::isPosterFormat('single_image', 'educational', null));
    }

    public function test_listicle_or_infographic_visual_direction_is_poster(): void
    {
        $this->assertTrue(ImageCreativeDirection::isPosterFormat('single_image', 'brand', 'A clean listicle of 5 tips'));
        $this->assertTrue(ImageCreativeDirection::isPosterFormat('single_image', 'brand', 'infographic explainer card'));
        $this->assertTrue(ImageCreativeDirection::isPosterFormat('single_image', 'brand', 'quote card on cream'));
    }

    public function test_lifestyle_single_image_stays_photo(): void
    {
        $this->assertFalse(ImageCreativeDirection::isPosterFormat('single_image', 'brand_moment', 'candid office photo, natural light'));
    }

    public function test_non_single_image_formats_never_poster(): void
    {
        // reel/video are photo-anchored keyframes; carousel has its own slide
        // path — none should hijack the poster route even when educational.
        $this->assertFalse(ImageCreativeDirection::isPosterFormat('reel', 'educational', null));
        $this->assertFalse(ImageCreativeDirection::isPosterFormat('video', 'educational', 'infographic'));
        $this->assertFalse(ImageCreativeDirection::isPosterFormat('carousel', 'educational', 'listicle'));
        $this->assertFalse(ImageCreativeDirection::isPosterFormat(null, null, null));
    }

    public function test_poster_directive_asks_for_legible_exact_text(): void
    {
        $d = ImageCreativeDirection::posterDirective();
        $this->assertStringContainsStringIgnoringCase('summary poster', $d);
        $this->assertStringContainsStringIgnoringCase('headline', $d);
        $this->assertStringContainsStringIgnoringCase('exact', $d);
        // It must instruct the model to RENDER text (the opposite of the photo
        // path's no-text guard) — it asks for a legible headline + points.
        $this->assertStringContainsStringIgnoringCase('legible', $d);
        $this->assertStringContainsStringIgnoringCase('render', $d);
    }

    public function test_poster_content_block_numbers_points_and_pins_headline(): void
    {
        $block = ImageCreativeDirection::posterContentBlock(
            'Hiring is broken',
            ['Screen for competence', 'Name the real fear', 'Be transparent early'],
        );

        $this->assertStringContainsString('headline: "Hiring is broken"', $block);
        $this->assertStringContainsString('1) Screen for competence.', $block);
        $this->assertStringContainsString('3) Be transparent early.', $block);
        $this->assertStringContainsStringIgnoringCase('spelled exactly', $block);
    }

    public function test_poster_content_block_skips_empty_points(): void
    {
        $block = ImageCreativeDirection::posterContentBlock('Title', ['One', '', '  ', 'Two']);

        $this->assertStringContainsString('1) One.', $block);
        $this->assertStringContainsString('2) Two.', $block);
        $this->assertStringNotContainsString('3)', $block);
    }

    public function test_carousel_routes_to_infographic(): void
    {
        // A carousel post is a multi-section explainer — it should become a
        // dense infographic, not a photo of slide one.
        $this->assertTrue(ImageCreativeDirection::isInfographicFormat('carousel', null, null));
        $this->assertTrue(ImageCreativeDirection::isInfographicFormat('carousel', 'brand', 'whatever'));
    }

    public function test_educational_single_image_also_qualifies_as_infographic(): void
    {
        // isInfographicFormat subsumes isPosterFormat; the caller gates the
        // single-image case further on panel count.
        $this->assertTrue(ImageCreativeDirection::isInfographicFormat('single_image', 'educational', null));
        $this->assertFalse(ImageCreativeDirection::isInfographicFormat('single_image', 'brand_moment', 'candid photo'));
        $this->assertFalse(ImageCreativeDirection::isInfographicFormat('reel', 'educational', null));
    }

    public function test_infographic_directive_describes_panels_and_grid(): void
    {
        $d = ImageCreativeDirection::infographicDirective(4);
        $this->assertStringContainsStringIgnoringCase('infographic', $d);
        $this->assertStringContainsStringIgnoringCase('title bar', $d);
        $this->assertStringContainsStringIgnoringCase('2x2', $d);
        $this->assertStringContainsStringIgnoringCase('takeaway', $d);
        $this->assertStringContainsStringIgnoringCase('exact', $d);
    }

    public function test_infographic_content_block_lays_out_panels_in_order(): void
    {
        $block = ImageCreativeDirection::infographicContentBlock(
            'Making the case for ethical AI',
            [
                ['heading' => 'Real cost of failure', 'bullets' => ['Systems torn out', 'Lost team trust'], 'illustration' => 'broken machine'],
                ['heading' => 'The adoption tax', 'bullets' => ['Low utilisation'], 'illustration' => 'chart'],
            ],
            'Cost-avoidance argument',
        );

        $this->assertStringContainsString('Title bar: "Making the case for ethical AI"', $block);
        $this->assertStringContainsString('Panel 1 heading: "Real cost of failure"', $block);
        $this->assertStringContainsString('Systems torn out; Lost team trust', $block);
        $this->assertStringContainsString('Panel 2 heading: "The adoption tax"', $block);
        $this->assertStringContainsString('Footer takeaway banner: "Cost-avoidance argument"', $block);
        // Illustration hints must be marked as picture-only (not printed text).
        $this->assertStringContainsString('[small illustration: broken machine]', $block);
        $this->assertStringContainsStringIgnoringCase('do NOT print the bracketed', $block);
    }
}
