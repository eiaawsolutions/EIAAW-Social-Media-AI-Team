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

        $text = $this->renderedText($blocks);
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
}
