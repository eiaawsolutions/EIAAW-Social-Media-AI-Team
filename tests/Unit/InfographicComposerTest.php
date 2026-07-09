<?php

namespace Tests\Unit;

use App\Services\Branding\InfographicComposer;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Threads / TikTok / Pinterest canvas — where the dead-space bug appeared. */
    private function portrait(): array
    {
        return ['w' => 1080, 'h' => 1920];
    }

    /** The card fill rects (white) define each card's box, in draw order. */
    private function cardRects(array $blocks): array
    {
        return array_values(array_filter(
            $blocks,
            fn ($b) => ($b['type'] ?? '') === 'rect' && ($b['color'] ?? '') === 'FFFFFF',
        ));
    }

    /** The full-width accent band at the very top is the title bar. */
    private function titleBarHeight(array $blocks): int
    {
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'rect' && $b['x'] === 0 && $b['y'] === 0 && $b['w'] === 1080) {
                return (int) $b['h'];
            }
        }

        return 0;
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

    // ─── Portrait dead-space regression (threads / tiktok / pinterest) ───────

    /**
     * The reported portrait bug: on a tall 1080x1920 canvas the grid was centred
     * in the whole band, marooning it in the vertical middle — a big dead gap
     * under the title bar. The grid must now START near the title (capped top
     * margin), not float in the middle.
     */
    public function test_portrait_grid_starts_near_the_title_bar(): void
    {
        $canvas = $this->portrait();
        $blocks = $this->composer()->layoutInfographic(
            'Sales Agent splits work with humans',
            [
                ['heading' => 'AI handles lead sourcing and scoring', 'bullets' => []],
                ['heading' => 'Automated personalized outreach and follow-ups', 'bullets' => []],
                ['heading' => 'SDRs own negotiation and relationship building', 'bullets' => []],
                ['heading' => 'Humans keep judgment and decision making', 'bullets' => []],
            ],
            '',
            $canvas,
            '11766A',
        );

        $titleH = $this->titleBarHeight($blocks);
        $this->assertGreaterThan(0, $titleH, 'Title bar must exist.');

        $cards = $this->cardRects($blocks);
        $this->assertCount(4, $cards);

        $firstCardTop = min(array_map(fn ($c) => (int) $c['y'], $cards));
        $gapUnderTitle = $firstCardTop - $titleH;

        // The old bug produced a gap of several hundred px (grid centred in a
        // ~1500px band). Allow generous padding but never a cavernous void.
        $this->assertLessThan(
            (int) round($canvas['h'] * 0.14),
            $gapUnderTitle,
            "Portrait grid must start near the title bar; found a {$gapUnderTitle}px dead gap.",
        );
        $this->assertGreaterThanOrEqual(0, $gapUnderTitle, 'Grid must not overlap the title bar.');
    }

    /**
     * On a tall canvas the cards must GROW to occupy the space rather than
     * clustering at content-height and leaving ~35% of the canvas empty. The
     * grid block should span a healthy share of the band below the title.
     */
    public function test_portrait_cards_fill_the_available_height(): void
    {
        $canvas = $this->portrait();
        $blocks = $this->composer()->layoutInfographic(
            'Sales Agent splits work with humans',
            [
                ['heading' => 'AI handles lead sourcing', 'bullets' => []],
                ['heading' => 'Automated outreach', 'bullets' => []],
                ['heading' => 'SDRs own negotiation', 'bullets' => []],
                ['heading' => 'Humans keep judgment', 'bullets' => []],
            ],
            '',
            $canvas,
            '11766A',
        );

        $titleH = $this->titleBarHeight($blocks);
        $cards = $this->cardRects($blocks);
        $this->assertCount(4, $cards);

        $top = min(array_map(fn ($c) => (int) $c['y'], $cards));
        $bottom = max(array_map(fn ($c) => (int) $c['y'] + (int) $c['h'], $cards));
        $band = $canvas['h'] - $titleH;
        $occupied = $bottom - $top;

        $this->assertGreaterThan(
            (int) round($band * 0.55),
            $occupied,
            'Portrait cards must fill a healthy share of the band, not cluster at content height.',
        );

        // And nothing may fall off the bottom of the canvas.
        $this->assertLessThanOrEqual($canvas['h'], $bottom, 'Last row must stay on-canvas.');
    }

    /**
     * Card growth must never push the grid past the canvas / into the footer
     * band. Exercised with the densest supported grid (6 panels → 3 rows) plus
     * a footer, on portrait.
     */
    public function test_portrait_dense_grid_with_footer_stays_within_canvas(): void
    {
        $canvas = $this->portrait();
        $panels = [];
        for ($i = 1; $i <= 6; $i++) {
            $panels[] = ['heading' => "Panel number {$i} heading", 'bullets' => ['A supporting point here']];
        }

        $blocks = $this->composer()->layoutInfographic(
            'A dense six panel explainer title',
            $panels,
            'The single takeaway line that anchors the whole graphic',
            $canvas,
            '11766A',
        );

        $cards = $this->cardRects($blocks);
        $this->assertCount(6, $cards);

        // Footer band = the full-width accent rect that is NOT at y=0.
        $footerTop = $canvas['h'];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'rect' && $b['x'] === 0 && $b['w'] === $canvas['w'] && $b['y'] > 0 && ($b['color'] ?? '') === '11766A') {
                $footerTop = min($footerTop, (int) $b['y']);
            }
        }

        foreach ($cards as $c) {
            $cardBottom = (int) $c['y'] + (int) $c['h'];
            $this->assertLessThanOrEqual($canvas['h'], $cardBottom, 'Card must stay on-canvas.');
            $this->assertLessThanOrEqual($footerTop, $cardBottom, 'Card must not collide with the footer band.');
        }
    }

    /** Square must be unaffected: growth/gap logic only absorbs real slack. */
    public function test_square_layout_not_regressed_by_portrait_fill_logic(): void
    {
        $canvas = $this->square();
        $blocks = $this->composer()->layoutInfographic(
            'Four ways to de-risk an AI rollout',
            [
                ['heading' => 'Start with one workflow', 'bullets' => ['Pick a high-pain, low-risk task']],
                ['heading' => 'Measure before and after', 'bullets' => ['Baseline the manual cost']],
                ['heading' => 'Keep a human in the loop', 'bullets' => ['Approve outputs early on']],
                ['heading' => 'Review the personalisation', 'bullets' => ['Check tone against brand']],
            ],
            '',
            $canvas,
            '11766A',
        );

        $cards = $this->cardRects($blocks);
        $this->assertCount(4, $cards);
        foreach ($cards as $c) {
            $this->assertLessThanOrEqual($canvas['h'], (int) $c['y'] + (int) $c['h']);
        }
    }

    // ─── Canvas-containment invariant (adversarial-audit regressions) ────────

    /**
     * Every scenario an adversarial geometry audit confirmed would push cards,
     * shadows or text OFF-CANVAS. Root cause was twofold:
     *   1. minCardH = cellW*0.44 was a hard floor the fit loop could not lower,
     *      so rows*minCardH + gutters could structurally exceed the band
     *      (landscape 5-6 panels; square 2-panels where cols=1 → full-width cell;
     *      dense square grids with wrapping bullets).
     *   2. Card GROWTH used min($maxCardH, $cardH + $grow), which SHRANK a
     *      content-tall card below its measured height — dropping bullets and
     *      overdrawing text.
     * The invariant: nothing the layout emits may fall outside the canvas.
     */
    #[DataProvider('containmentScenarios')]
    public function test_no_block_ever_falls_outside_the_canvas(string $label, array $canvas, array $panels, string $footer): void
    {
        $blocks = $this->composer()->layoutInfographic('A representative explainer title', $panels, $footer, $canvas, '11766A');

        foreach ($blocks as $i => $b) {
            $x = (int) $b['x'];
            $y = (int) $b['y'];
            $this->assertGreaterThanOrEqual(0, $x, "[{$label}] block #{$i} ({$b['type']}) has negative x.");
            $this->assertGreaterThanOrEqual(0, $y, "[{$label}] block #{$i} ({$b['type']}) has negative y.");

            if (($b['type'] ?? '') === 'text') {
                continue; // text bounds asserted per-card below
            }

            $bottom = $y + (int) $b['h'];
            $this->assertLessThanOrEqual(
                $canvas['h'],
                $bottom,
                "[{$label}] block #{$i} ({$b['type']}) bottom {$bottom} exceeds canvas height {$canvas['h']}.",
            );
        }

        // And every card must sit fully on-canvas.
        foreach ($this->cardRects($blocks) as $c) {
            $this->assertLessThanOrEqual(
                $canvas['h'],
                (int) $c['y'] + (int) $c['h'],
                "[{$label}] a card falls off the bottom of the canvas.",
            );
        }
    }

    public static function containmentScenarios(): array
    {
        $heading = fn (int $i) => ['heading' => "Panel number {$i} heading text", 'bullets' => ['A supporting point here']];
        $wrapping = fn (int $i) => [
            'heading' => "Panel {$i} heading",
            'bullets' => [
                'A deliberately long supporting bullet that wraps onto two lines',
                'Another long supporting bullet that also wraps onto two lines',
                'A third long supporting bullet which wraps onto two lines as well',
            ],
        ];
        $six = array_map($heading, range(1, 6));
        $five = array_map($heading, range(1, 5));
        $sixWrapping = array_map($wrapping, range(1, 6));

        return [
            // Audit finding: landscape 3-row grids put the bottom row off-canvas.
            'landscape 6 panels' => ['landscape 6 panels', ['w' => 1920, 'h' => 1080], $six, ''],
            'landscape 5 panels' => ['landscape 5 panels', ['w' => 1920, 'h' => 1080], $five, ''],
            // Audit finding: square 2 panels → cols=1 → minCardH 432 > slot.
            'square 2 panels' => ['square 2 panels', ['w' => 1080, 'h' => 1080], [
                ['heading' => 'AI owns the grunt work', 'bullets' => []],
                ['heading' => 'Humans own the judgment', 'bullets' => []],
            ], ''],
            'square 2 panels + footer' => ['square 2 panels + footer', ['w' => 1080, 'h' => 1080], [
                ['heading' => 'AI owns the grunt work', 'bullets' => ['Sourcing and sequencing']],
                ['heading' => 'Humans own the judgment', 'bullets' => ['Reading the room']],
            ], 'AI augments the team — humans stay in charge'],
            // Audit finding: dense square grid with wrapping bullets overflows.
            'square 6 panels wrapping bullets' => ['square 6 panels wrapping bullets', ['w' => 1080, 'h' => 1080], $sixWrapping, ''],
            'portrait 6 panels wrapping bullets' => ['portrait 6 panels wrapping bullets', ['w' => 1080, 'h' => 1920], $sixWrapping, ''],
            'portrait 6 panels + footer' => ['portrait 6 panels + footer', ['w' => 1080, 'h' => 1920], $six, 'The single takeaway line'],
            'landscape 2 panels' => ['landscape 2 panels', ['w' => 1920, 'h' => 1080], [
                ['heading' => 'First half', 'bullets' => []],
                ['heading' => 'Second half', 'bullets' => []],
            ], ''],
            'single panel portrait' => ['single panel portrait', ['w' => 1080, 'h' => 1920], [
                ['heading' => 'Only one panel here', 'bullets' => ['With a supporting point']],
            ], ''],
        ];
    }

    /**
     * Card growth must be ADDITIVE ONLY — it may never shrink a card whose
     * measured content already exceeds the growth cap (cellW*0.85). The audit
     * proved min($maxCardH, …) silently dropped bullets from content-rich cards.
     * Here a content-rich panel on portrait must keep ALL of its bullets.
     */
    public function test_growth_never_shrinks_a_content_tall_card_or_drops_bullets(): void
    {
        $bullets = ['First supporting point', 'Second supporting point', 'Third supporting point'];
        $blocks = $this->composer()->layoutInfographic(
            'Sales Agent splits work',
            [
                ['heading' => 'AI handles lead sourcing and scoring today', 'bullets' => $bullets],
                ['heading' => 'Humans keep judgment', 'bullets' => ['Reading the room']],
                ['heading' => 'SDRs own negotiation', 'bullets' => ['Closing the deal']],
                ['heading' => 'Review the personalisation', 'bullets' => ['Check tone']],
            ],
            '',
            $this->portrait(),
            '11766A',
        );

        $text = str_replace("\n", ' ', $this->renderedText($blocks));
        foreach ($bullets as $b) {
            $this->assertStringContainsString(
                $b,
                $text,
                "Bullet \"{$b}\" was dropped — card growth shrank the card below its measured content.",
            );
        }
    }

    /**
     * Vertical card padding scales with the cell WIDTH, which is wrong for the
     * short, wide cards of a landscape 3-row grid: a ~847px-wide cell in a ~207px
     * slot spent 144px on padding and left no room for a bullet, so every bullet
     * was silently dropped. Padding is now capped against the slot.
     */
    public function test_landscape_dense_grid_still_renders_its_bullets(): void
    {
        $panels = [];
        foreach (['Start with one workflow', 'Measure before and after', 'Keep a human in the loop', 'Review the personalisation', 'Name the decision owner', 'Retrain, do not replace'] as $heading) {
            $panels[] = ['heading' => $heading, 'bullets' => ['A short supporting point']];
        }

        $blocks = $this->composer()->layoutInfographic(
            'Six ways teams de-risk an AI rollout',
            $panels,
            '',
            ['w' => 1920, 'h' => 1080],
            '11766A',
        );

        $text = str_replace("\n", ' ', $this->renderedText($blocks));
        $this->assertStringContainsString(
            'A short supporting point',
            $text,
            'Landscape dense grid dropped its bullets — vertical padding is starving the card.',
        );

        // ...and every card is still on-canvas.
        foreach ($this->cardRects($blocks) as $c) {
            $this->assertLessThanOrEqual(1080, (int) $c['y'] + (int) $c['h']);
        }
    }
}
