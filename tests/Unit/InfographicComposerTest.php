<?php

namespace Tests\Unit;

use App\Services\Branding\InfographicComposer;
use Tests\TestCase;

/**
 * Guards the PROGRAMMATIC text compositor that replaced diffusion-rendered
 * infographic/poster text. The whole point of this class is that the copy it
 * lays out is EXACT — the words the Writer/PosterContentWriter produced, drawn
 * verbatim, never garbled the way Nano Banana garbled them ("Step 3"→"Step 33",
 * "outreach"→"outrech"). These tests exercise the pure layout/wrap/fit logic
 * (no FFmpeg) and assert the exact strings survive into the draw blocks.
 */
class InfographicComposerTest extends TestCase
{
    private function composer(): InfographicComposer
    {
        // ffmpegBin is never invoked by the pure layout methods under test.
        return new InfographicComposer('ffmpeg');
    }

    private function square(): array
    {
        return ['w' => 1080, 'h' => 1080];
    }

    /** Collect the concatenated text of all 'text' blocks for substring asserts. */
    private function renderedText(array $blocks): string
    {
        return collect($blocks)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\u{241F}"); // unit-separator join so words from different blocks don't fuse
    }

    // ─── Exact-text fidelity (the anti-garble guarantee) ────────────────────

    public function test_infographic_renders_exact_heading_and_bullet_text(): void
    {
        $blocks = $this->composer()->layoutInfographic(
            'What Sales Agent does',
            [
                ['heading' => 'Lead generation', 'bullets' => ['Finds and researches prospects']],
                ['heading' => 'Personalised outreach', 'bullets' => ['Drafts personalised messages', 'Human refines tone']],
            ],
            'AI takes the grunt work',
            $this->square(),
            '11766A',
        );

        $text = $this->renderedText($blocks);

        // The exact words must appear — these are the very strings the old
        // diffusion path corrupted into "outrech", "Revines", "intraction".
        $this->assertStringContainsString('Lead generation', $text);
        $this->assertStringContainsString('Personalised outreach', $text);
        $this->assertStringContainsString('Finds and researches prospects', $text);
        $this->assertStringContainsString('Drafts personalised messages', $text);
        $this->assertStringContainsString('AI takes the grunt work', $text);
        $this->assertStringContainsString('What Sales Agent does', $text);

        // And NONE of the known garble artefacts may appear.
        foreach (['outrech', 'Revines', 'intraction', 'Step 33', 'Hunines'] as $garbage) {
            $this->assertStringNotContainsString($garbage, $text);
        }
    }

    public function test_infographic_numbers_panels_in_order(): void
    {
        $blocks = $this->composer()->layoutInfographic(
            'Title',
            [
                ['heading' => 'First', 'bullets' => []],
                ['heading' => 'Second', 'bullets' => []],
                ['heading' => 'Third', 'bullets' => []],
            ],
            '',
            $this->square(),
            '11766A',
        );

        $text = $this->renderedText($blocks);
        // Headings carry a leading ordinal chip ("1  First", "2  Second", …).
        $this->assertMatchesRegularExpression('/1\s+First/', $text);
        $this->assertMatchesRegularExpression('/2\s+Second/', $text);
        $this->assertMatchesRegularExpression('/3\s+Third/', $text);
    }

    public function test_poster_renders_exact_points_in_order(): void
    {
        $blocks = $this->composer()->layoutPoster(
            'Five hiring truths',
            ['Screen for competence', 'Name the real fear', 'Be transparent early'],
            $this->square(),
            '11766A',
        );

        // Layout may insert soft line-breaks when a string is wider than its
        // column — that's legitimate wrapping, not garble. The anti-garble
        // guarantee is that every WORD survives verbatim, so normalise newlines
        // to spaces before asserting the exact phrases are present.
        $text = str_replace("\n", ' ', $this->renderedText($blocks));
        $this->assertStringContainsString('Five hiring truths', $text);
        $this->assertStringContainsString('Screen for competence', $text);
        $this->assertStringContainsString('Name the real fear', $text);
        $this->assertStringContainsString('Be transparent early', $text);
        // Numbered chips 1..3 present as standalone text blocks.
        $this->assertStringContainsString("1", $text);
        $this->assertStringContainsString("3", $text);
    }

    // ─── Layout structure ───────────────────────────────────────────────────

    public function test_infographic_has_title_bar_and_footer_bands(): void
    {
        $blocks = $this->composer()->layoutInfographic(
            'T',
            [['heading' => 'A', 'bullets' => []], ['heading' => 'B', 'bullets' => []]],
            'Footer line',
            $this->square(),
            'FF0000',
        );

        $rects = array_values(array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'rect'));
        // At least: title bar + 2 panel cards (+ accent rules) + footer band.
        $this->assertGreaterThanOrEqual(4, count($rects));

        // Title bar is the full-width band at the very top using the accent.
        $titleBar = $rects[0];
        $this->assertSame(0, $titleBar['x']);
        $this->assertSame(0, $titleBar['y']);
        $this->assertSame(1080, $titleBar['w']);
        $this->assertSame('FF0000', $titleBar['color']);
    }

    public function test_no_footer_band_when_footer_empty(): void
    {
        $withFooter = $this->composer()->layoutInfographic(
            'T', [['heading' => 'A', 'bullets' => []], ['heading' => 'B', 'bullets' => []]], 'Footer', $this->square(), '11766A'
        );
        $withoutFooter = $this->composer()->layoutInfographic(
            'T', [['heading' => 'A', 'bullets' => []], ['heading' => 'B', 'bullets' => []]], '', $this->square(), '11766A'
        );

        $this->assertGreaterThan(
            count(array_filter($withoutFooter, fn ($b) => ($b['type'] ?? '') === 'text')),
            count(array_filter($withFooter, fn ($b) => ($b['type'] ?? '') === 'text')),
            'Footer text block should only exist when a footer is provided.'
        );
    }

    // ─── Word-wrap + font-fit (no overflow) ─────────────────────────────────

    public function test_wrap_to_width_breaks_long_text_into_lines(): void
    {
        $wrapped = $this->composer()->wrapToWidth(
            'Finds and researches prospects across many channels every day',
            300,
            34,
        );
        $this->assertStringContainsString("\n", $wrapped);
        // No information loss — every original word survives the wrap.
        foreach (['Finds', 'researches', 'prospects', 'channels'] as $word) {
            $this->assertStringContainsString($word, $wrapped);
        }
    }

    public function test_wrap_preserves_short_text_on_one_line(): void
    {
        $wrapped = $this->composer()->wrapToWidth('Lead scoring', 600, 34);
        $this->assertSame('Lead scoring', $wrapped);
    }

    public function test_fit_font_size_shrinks_for_long_single_word(): void
    {
        $big = $this->composer()->fitFontSize('Hi', 400, 24, 80);
        $small = $this->composer()->fitFontSize('personalisationworkflow', 400, 24, 80);
        $this->assertLessThan($big, $small, 'A long unbreakable word must get a smaller font to fit.');
        $this->assertGreaterThanOrEqual(24, $small, 'Font never drops below the floor.');
        $this->assertLessThanOrEqual(80, $big, 'Font never exceeds the ceiling.');
    }

    // ─── Anti-clipping regression (the screenshot bug) ──────────────────────

    /**
     * The screenshot bug: a heading like "Name human decision explicitly" was
     * clipped mid-word ("Name human decisi…") because the "1  " ordinal chip was
     * prepended AFTER the heading was wrapped to the full card width, pushing the
     * first line past the card edge (FFmpeg drawtext never clips). The fix folds
     * the ordinal into the width budget, so no drawn line's estimated pixel width
     * exceeds the card's text column. This asserts that invariant directly.
     */
    public function test_infographic_heading_lines_never_exceed_card_text_width(): void
    {
        $w = 1080;
        // Realistic long headings from the reported "AI vendor demos" carousel.
        $blocks = $this->composer()->layoutInfographic(
            'Prepare before AI vendor demos',
            [
                ['heading' => 'Name human decision makers explicitly', 'bullets' => []],
                ['heading' => 'Help the team understand role changes', 'bullets' => []],
                ['heading' => 'Ensure work stays meaningful', 'bullets' => []],
            ],
            '',
            ['w' => $w, 'h' => 1080],
            '11766A',
        );

        // Reconstruct the card text column: a 2-col grid at pad 4.5%, gutter 2.8%.
        $pad = (int) round($w * 0.045);
        $gutter = (int) round($w * 0.028);
        $gridW = $w - 2 * $pad;
        $cellW = (int) floor(($gridW - $gutter) / 2); // 2 columns for 3 panels
        $spineW = max(8, (int) round($cellW * 0.028));
        $innerPad = (int) round($cellW * 0.075);
        $panelTextW = $cellW - $spineW - 2 * $innerPad;

        // Every heading text block's widest line must fit the card text column
        // using the SAME glyph estimate the composer wraps with (0.60em).
        $glyphEm = 0.60;
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') !== 'text') {
                continue;
            }
            // Skip the title band block (x == pad, sits above the grid) and the
            // ghost watermark (single digit). Only inspect card content blocks.
            $isCardBlock = $b['x'] > $pad;
            if (! $isCardBlock) {
                continue;
            }
            foreach (explode("\n", $b['text']) as $line) {
                $estW = mb_strlen($line) * $b['size'] * $glyphEm;
                $this->assertLessThanOrEqual(
                    $panelTextW + 1, // +1 for rounding
                    $estW,
                    sprintf('Card line "%s" (%.0fpx) overflows the %dpx text column — would clip.', $line, $estW, $panelTextW),
                );
            }
        }
    }

    /**
     * Cards must hug their content, not be force-stretched to an even split of
     * the grid — the old even-split turned heading-only panels into giant empty
     * boxes. With three short heading-only panels the cards should be well under
     * half the canvas height.
     */
    public function test_heading_only_cards_are_content_sized_not_stretched(): void
    {
        $h = 1080;
        $blocks = $this->composer()->layoutInfographic(
            'Title',
            [
                ['heading' => 'One', 'bullets' => []],
                ['heading' => 'Two', 'bullets' => []],
                ['heading' => 'Three', 'bullets' => []],
                ['heading' => 'Four', 'bullets' => []],
            ],
            '',
            ['w' => 1080, 'h' => $h],
            '11766A',
        );

        // Find the card-fill rects (full cellW panels, colour = PANEL white).
        $cardRects = array_values(array_filter(
            $blocks,
            fn ($b) => ($b['type'] ?? '') === 'rect' && ($b['color'] ?? '') === 'FFFFFF',
        ));
        $this->assertCount(4, $cardRects, 'One fill rect per panel.');
        foreach ($cardRects as $rect) {
            $this->assertLessThan(
                (int) round($h * 0.42),
                $rect['h'],
                'Heading-only cards must be content-sized, not stretched to fill the grid.',
            );
        }
    }

    /**
     * The reported bug: bullet text spilled BELOW the card's bottom edge because
     * the card was clamped to the even grid slot while text still drew at full
     * content height. Every drawn text/bullet block inside a card must sit within
     * that card's [top, bottom] band. Uses heading+bullet panels dense enough to
     * have triggered the old clamp.
     */
    public function test_card_content_never_overflows_the_card_box(): void
    {
        $blocks = $this->composer()->layoutInfographic(
            'Prepare before AI vendor demos',
            [
                ['heading' => 'Name human decision makers explicitly', 'bullets' => ['Who signs off, in writing']],
                ['heading' => 'Help the team understand role changes', 'bullets' => ['Map tasks that shift', 'Retrain, not replace']],
                ['heading' => 'Ensure work stays meaningful', 'bullets' => ['Keep judgement human']],
            ],
            'AI augments the team — humans stay in charge',
            $this->square(),
            '11766A',
        );

        // Card fill rects (white) define each card's box.
        $cards = array_values(array_filter(
            $blocks,
            fn ($b) => ($b['type'] ?? '') === 'rect' && ($b['color'] ?? '') === 'FFFFFF',
        ));
        $this->assertCount(3, $cards);

        // Every content text block (ink heading or muted bullet — not the cream
        // title/footer, not the ghost watermark) must start inside some card and
        // its full wrapped height must end at or before that card's bottom edge.
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') !== 'text') {
                continue;
            }
            $isContent = in_array($b['color'] ?? '', ['0F1A1D', '5A6B68'], true); // INK | MUTED
            if (! $isContent) {
                continue;
            }
            // Find the card whose box vertically brackets this block's top.
            $card = null;
            foreach ($cards as $c) {
                if ($b['x'] >= $c['x'] && $b['y'] >= $c['y'] && $b['y'] < $c['y'] + $c['h']) {
                    $card = $c;
                    break;
                }
            }
            $this->assertNotNull($card, sprintf('Content block at y=%d not inside any card.', $b['y']));

            $lines = substr_count($b['text'], "\n") + 1;
            $lineH = (int) round($b['size'] * 1.28); // upper bound on a line's height
            $blockBottom = $b['y'] + $lines * $lineH;
            $this->assertLessThanOrEqual(
                $card['y'] + $card['h'],
                $blockBottom,
                sprintf('Text "%s" (bottom %d) spills past card bottom %d.', str_replace("\n", ' ', $b['text']), $blockBottom, $card['y'] + $card['h']),
            );
        }
    }

    public function test_cards_have_drop_shadow_layers(): void
    {
        $blocks = $this->composer()->layoutInfographic(
            'T',
            [['heading' => 'A', 'bullets' => []], ['heading' => 'B', 'bullets' => []]],
            '',
            $this->square(),
            '11766A',
        );

        $shadows = array_filter($blocks, fn ($b) => ($b['type'] ?? '') === 'shadow');
        $this->assertNotEmpty($shadows, 'Cards must render soft drop-shadow layers.');
        foreach ($shadows as $s) {
            $this->assertGreaterThan(0.0, $s['alpha']);
            $this->assertLessThanOrEqual(1.0, $s['alpha']);
        }
    }
}
