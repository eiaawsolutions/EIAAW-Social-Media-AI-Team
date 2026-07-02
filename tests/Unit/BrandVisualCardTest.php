<?php

namespace Tests\Unit;

use App\Models\BrandStyle;
use App\Services\Imagery\BrandVisualCard;
use Tests\TestCase;

/**
 * BrandVisualCard is the client analogue of EiaawBrandLock — it renders a
 * brand's own visual identity into the image prompt. These lock: (1) a
 * structured visual_identity renders palette + mood + imagery + forbidden;
 * (2) a palette-only or voice-only brand still produces a usable card via the
 * Phase-1 synthesis; (3) an empty brand is NOT available (so DesignerAgent
 * keeps its exact legacy prompt — zero regression); (4) the length guard trims
 * the low-value clauses first. All DB-free (BrandStyle built in-memory).
 */
class BrandVisualCardTest extends TestCase
{
    private function styleWith(array $attrs): BrandStyle
    {
        $style = new BrandStyle;
        $style->forceFill($attrs);

        return $style;
    }

    public function test_renders_structured_visual_identity(): void
    {
        $style = $this->styleWith([
            'visual_identity' => [
                'palette' => [
                    ['hex' => '#11766A', 'name' => 'Deep teal', 'role' => 'accent'],
                    ['hex' => 'FAF7F2', 'name' => 'Cream', 'role' => 'background'],
                ],
                'mood_adjectives' => ['warm', 'editorial', 'calm'],
                'imagery_style' => 'real objects on natural surfaces',
                'composition_rules' => 'asymmetric, generous negative space',
                'forbidden_visuals' => ['neon glow', 'purple gradients'],
            ],
        ]);

        $card = BrandVisualCard::forStyle($style, 'Acme Co', 'food');

        $this->assertTrue($card->isAvailable());
        $directive = $card->imageDirective();

        $this->assertStringContainsString('Acme Co house style', $directive);
        $this->assertStringContainsStringIgnoringCase('Deep teal #11766A (accent)', $directive);
        $this->assertStringContainsStringIgnoringCase('Cream #FAF7F2 (background)', $directive);
        $this->assertStringContainsStringIgnoringCase('warm, editorial and calm', $directive);
        $this->assertStringContainsStringIgnoringCase('real objects on natural surfaces', $directive);
        $this->assertStringContainsStringIgnoringCase('asymmetric', $directive);
        $this->assertStringContainsStringIgnoringCase('neon glow', $directive);
    }

    public function test_accent_hex_is_first_valid_palette_colour(): void
    {
        $style = $this->styleWith([
            'visual_identity' => [
                'palette' => [
                    ['hex' => 'not-a-hex'],
                    ['hex' => '#1FA896', 'name' => 'Bright teal'],
                ],
            ],
        ]);

        $this->assertSame('1FA896', BrandVisualCard::forStyle($style, 'Acme')->accentHex());
    }

    public function test_phase1_synthesis_from_legacy_palette_column(): void
    {
        // No structured visual_identity — only the legacy palette column that
        // Phase 2 will populate. The card must still light up from it.
        $style = $this->styleWith([
            'palette' => ['#004E89', '1FA896', 'FAF7F2'],
        ]);

        $card = BrandVisualCard::forStyle($style, 'Acme', 'saas');

        $this->assertTrue($card->isAvailable());
        $this->assertStringContainsStringIgnoringCase('#004E89', $card->imageDirective());
        // Industry fallback gives a subject cue even with palette only.
        $this->assertStringContainsStringIgnoringCase('real people at work', $card->imageDirective());
    }

    public function test_phase1_synthesis_from_voice_tone_needs_imagery_to_be_available(): void
    {
        // Voice tone alone (mood) without palette or imagery cue: mood + an
        // industry imagery cue → available (mood + imagery), and mood renders.
        $style = $this->styleWith([
            'voice_attributes' => ['tone' => ['bold', 'playful']],
        ]);

        $card = BrandVisualCard::forStyle($style, 'Acme', 'fashion');

        $this->assertTrue($card->isAvailable());
        $this->assertStringContainsStringIgnoringCase('bold and playful', $card->imageDirective());
        $this->assertStringContainsStringIgnoringCase('worn or in use', $card->imageDirective());
    }

    public function test_empty_brand_is_not_available(): void
    {
        // No palette, no mood, no imagery, no industry → the card must be
        // unavailable so DesignerAgent falls back to its exact legacy prompt.
        $card = BrandVisualCard::forStyle($this->styleWith([]), 'Acme', '');
        $this->assertFalse($card->isAvailable());
        $this->assertSame('', $card->imageDirective());

        // Null style (brand never onboarded) is also unavailable.
        $this->assertFalse(BrandVisualCard::forStyle(null, 'Acme', '')->isAvailable());
    }

    public function test_mood_only_without_industry_is_not_available(): void
    {
        // Mood but no palette and no industry imagery cue → not enough signal.
        $style = $this->styleWith([
            'voice_attributes' => ['tone' => ['confident']],
        ]);
        $this->assertFalse(BrandVisualCard::forStyle($style, 'Acme', '')->isAvailable());
    }

    public function test_directive_respects_length_budget_and_trims_low_value_clauses_first(): void
    {
        $style = $this->styleWith([
            'visual_identity' => [
                'palette' => [['hex' => '11766A', 'name' => 'Teal', 'role' => 'accent']],
                'mood_adjectives' => ['warm', 'editorial'],
                'imagery_style' => str_repeat('very specific imagery guidance ', 40),
                'forbidden_visuals' => ['UNIQUEFORBIDDENTOKEN glow'],
            ],
        ]);

        $directive = BrandVisualCard::forStyle($style, 'Acme')->imageDirective();

        $this->assertLessThanOrEqual(BrandVisualCard::MAX_DIRECTIVE_CHARS, mb_strlen($directive));
        // Palette survives; the trailing forbidden clause is dropped first.
        $this->assertStringContainsStringIgnoringCase('#11766A', $directive);
        $this->assertStringNotContainsString('UNIQUEFORBIDDENTOKEN', $directive);
    }

    public function test_poster_style_clause_carries_palette_and_mood(): void
    {
        $style = $this->styleWith([
            'visual_identity' => [
                'palette' => [['hex' => '11766A', 'name' => 'Teal']],
                'mood_adjectives' => ['warm'],
            ],
        ]);

        $clause = BrandVisualCard::forStyle($style, 'Acme')->posterStyleClause();
        $this->assertStringContainsStringIgnoringCase('poster', $clause);
        $this->assertStringContainsStringIgnoringCase('#11766A', $clause);
    }
}
