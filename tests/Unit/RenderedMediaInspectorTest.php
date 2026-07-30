<?php

namespace Tests\Unit;

use App\Services\Imagery\RenderedMediaInspector;
use Tests\TestCase;

/**
 * Compliance check 9 (media_quality) — the measurement half.
 *
 * Fixtures are drawn with GD at test time rather than committed as binaries, so
 * the repo stays text-only and the shapes stay readable. Each one reproduces a
 * signature MEASURED on real production assets on 2026-07-30:
 *
 *   fixture                    real counterpart                     edge_density
 *   ─────────────────────────  ───────────────────────────────────  ────────────
 *   blankPosterBackground()    the six live blanks (#541-#546)      0.0032-0.0171
 *   composedPoster()           same background + composited text    0.0727-0.0853
 *   photograph()               operator upload / branded artifact   0.1181-0.1928
 *
 * The threshold sits at 0.035 — the geometric midpoint of the 4.25x gap between
 * the worst real blank and the weakest real creative.
 */
class RenderedMediaInspectorTest extends TestCase
{
    /** @var array<int,string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    private function write(\GdImage $im): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inspect_').'.png';
        imagepng($im, $path);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * The exact thing that shipped: a cream canvas with a deep-teal header band
     * and nothing else. Note it is NOT uniform — the band alone puts
     * ink_coverage above 20%, which is precisely why ink cannot be the
     * discriminator and edge_density has to be.
     */
    private function blankPosterBackground(): \GdImage
    {
        $im = imagecreatetruecolor(600, 750);
        imagefill($im, 0, 0, imagecolorallocate($im, 0xFA, 0xF7, 0xF2)); // cream
        imagefilledrectangle($im, 0, 0, 599, 110, imagecolorallocate($im, 0x11, 0x76, 0x6A)); // teal band

        return $im;
    }

    private function solidCanvas(): \GdImage
    {
        $im = imagecreatetruecolor(600, 750);
        imagefill($im, 0, 0, imagecolorallocate($im, 0xFA, 0xF7, 0xF2));

        return $im;
    }

    /** The same background after InfographicComposer has drawn the copy on it. */
    private function composedPoster(): \GdImage
    {
        $im = $this->blankPosterBackground();
        $ink = imagecolorallocate($im, 0x0F, 0x1A, 0x1D);

        // Glyph-scale marks: many small high-contrast rectangles, which is what
        // rendered lettering looks like to an edge detector.
        for ($line = 0; $line < 14; $line++) {
            $y = 170 + $line * 38;
            for ($x = 60; $x < 540; $x += 11) {
                imagefilledrectangle($im, $x, $y, $x + 5, $y + 22, $ink);
            }
        }

        return $im;
    }

    /** Broad detail across the frame, like a real photograph. */
    private function photograph(): \GdImage
    {
        $im = imagecreatetruecolor(600, 750);
        for ($y = 0; $y < 750; $y += 3) {
            for ($x = 0; $x < 600; $x += 3) {
                $v = (int) (127 + 120 * sin($x / 7.0) * cos($y / 5.0));
                $c = imagecolorallocate($im, $v, max(0, $v - 30), min(255, $v + 25));
                imagefilledrectangle($im, $x, $y, $x + 2, $y + 2, $c);
            }
        }

        return $im;
    }

    // ── The regression these tests exist for ─────────────────────────────

    public function test_blank_poster_background_is_rejected(): void
    {
        $r = (new RenderedMediaInspector)->inspect($this->write($this->blankPosterBackground()));

        $this->assertFalse($r['ok'], 'An empty poster scaffold must never be judged publishable.');
        $this->assertSame(RenderedMediaInspector::VERDICT_LOW_DETAIL, $r['verdict']);
    }

    public function test_the_coloured_band_alone_produces_high_ink_so_ink_cannot_be_the_discriminator(): void
    {
        // Documents WHY the gate keys on edge_density. Measured on the real
        // assets: ink_coverage 0.2281-0.3175 on images that were entirely blank.
        $r = (new RenderedMediaInspector)->inspect($this->write($this->blankPosterBackground()));

        $this->assertGreaterThan(0.10, $r['metrics']['ink_coverage']);
        $this->assertLessThan(0.035, $r['metrics']['edge_density']);
    }

    public function test_solid_canvas_is_rejected_as_blank(): void
    {
        $r = (new RenderedMediaInspector)->inspect($this->write($this->solidCanvas()));

        $this->assertFalse($r['ok']);
        $this->assertSame(RenderedMediaInspector::VERDICT_BLANK, $r['verdict']);
    }

    public function test_composed_poster_passes(): void
    {
        $r = (new RenderedMediaInspector)->inspect($this->write($this->composedPoster()));

        $this->assertTrue($r['ok'], 'A poster with its copy drawn on must pass: '.$r['reason']);
        $this->assertSame(RenderedMediaInspector::VERDICT_OK, $r['verdict']);
    }

    public function test_photograph_passes(): void
    {
        $r = (new RenderedMediaInspector)->inspect($this->write($this->photograph()));

        $this->assertTrue($r['ok'], $r['reason']);
    }

    public function test_composing_text_moves_the_metric_across_the_threshold(): void
    {
        // The A/B that matters: identical background, one with copy drawn on.
        // Measured on production assets this was a ~23x jump (0.0032 → 0.0727).
        $inspector = new RenderedMediaInspector;
        $blank = $inspector->inspect($this->write($this->blankPosterBackground()));
        $composed = $inspector->inspect($this->write($this->composedPoster()));

        $this->assertLessThan($composed['metrics']['edge_density'], $blank['metrics']['edge_density']);
        $this->assertFalse($blank['ok']);
        $this->assertTrue($composed['ok']);
    }

    // ── Calibration lock ─────────────────────────────────────────────────

    public function test_edge_density_threshold_matches_the_measured_calibration(): void
    {
        // Real separation measured 2026-07-30: worst blank 0.0171, weakest real
        // creative 0.0727. Anything outside this band means the gate has been
        // retuned past the evidence — re-measure before changing it.
        $threshold = (float) config('services.branding.media_vetting.min_edge_density');

        $this->assertGreaterThan(0.0171, $threshold, 'Threshold must sit above the worst measured blank.');
        $this->assertLessThan(0.0727, $threshold, 'Threshold must sit below the weakest measured real creative.');
    }

    // ── Failure handling ─────────────────────────────────────────────────

    public function test_missing_file_is_unreadable_not_ok(): void
    {
        $r = (new RenderedMediaInspector)->inspect(sys_get_temp_dir().'/definitely-not-here-'.uniqid().'.png');

        $this->assertFalse($r['ok']);
        $this->assertSame(RenderedMediaInspector::VERDICT_UNREADABLE, $r['verdict']);
    }

    public function test_non_image_bytes_are_unreadable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notimg_');
        file_put_contents($path, 'this is not an image');
        $this->tmpFiles[] = $path;

        $r = (new RenderedMediaInspector)->inspect($path);

        $this->assertFalse($r['ok']);
        $this->assertSame(RenderedMediaInspector::VERDICT_UNREADABLE, $r['verdict']);
        $this->assertStringContainsString('not a decodable image', $r['reason']);
    }
}
