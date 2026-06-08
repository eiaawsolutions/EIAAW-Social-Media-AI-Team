<?php

namespace App\Services\Branding;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Renders the TEXT of a summary poster or multi-panel infographic
 * PROGRAMMATICALLY (FFmpeg drawtext) on top of a text-free AI-generated
 * background — instead of asking the diffusion image model (Nano Banana /
 * Gemini) to type the words itself.
 *
 * WHY THIS EXISTS
 * ----------------
 * Diffusion / autoregressive image models garble text at infographic density.
 * The old path fed Nano Banana a "render these words EXACTLY" prompt; the model
 * still produced "Step 33" for "Step 3", "outrech" for "outreach", "Revines"
 * for "Refines", and outright gibberish ("Hunines"). No prompt instruction can
 * fix a pixel-level rendering limitation — the only way to GUARANTEE correct
 * spelling is to draw the exact string ourselves.
 *
 * The source copy is already clean: PosterContentWriter distils the draft into
 * a title + points / panels via a JSON-schema-locked Haiku call, correctly
 * spelled. This composer typesets that copy onto a clean background so what the
 * customer publishes says exactly what the Writer wrote.
 *
 * Sibling to {@see BrandImageStamper} (the quote-card stamper) — same FFmpeg
 * drawtext + Symfony Process safety model (every value passed as a separate
 * argv item / read from a textfile, never shell-escaped), same font resolution,
 * same soft-fail contract (throws RuntimeException; the DesignerAgent caller
 * catches and falls back to the raw background image rather than shipping no
 * media). The two differ only in layout: the stamper composites a side/bottom
 * QUOTE panel; this composer lays out a TITLE BAR → PANEL GRID → FOOTER.
 *
 * Output: a stamped JPEG written under storage/app/branding/ (not uploaded —
 * the caller hands the local path back to the publish-provider re-host).
 *
 * Palette + type spine: references/eiaaw-design-system.md (cream/teal/ink). The
 * client path overrides the accent with the brand's own primary colour so the
 * graphic stays on-brand.
 */
class InfographicComposer
{
    /** EIAAW palette tokens — must match references/eiaaw-design-system.md and BrandImageStamper. */
    private const COLOR_INK = '0F1A1D';        // near-black primary text

    private const COLOR_CREAM = 'FAF7F2';      // warm-cream canvas

    private const COLOR_PANEL = 'FFFFFF';      // panel card fill (slightly brighter than canvas)

    private const COLOR_TEAL_DEEP = '11766A';  // deep-teal accent (title bar, footer, rules)

    private const COLOR_MUTED = '5A6B68';      // muted ink for bullets

    /**
     * Per-aspect canvas geometry. Matches FAL's output sizing the publish
     * pipeline already assumes (square 1080, portrait 1080x1920, landscape
     * 1920x1080). All band/padding values are in pixels at these sizes.
     */
    private const CANVAS = [
        'square' => ['w' => 1080, 'h' => 1080],
        'portrait' => ['w' => 1080, 'h' => 1920],
        'landscape' => ['w' => 1920, 'h' => 1080],
    ];

    public function __construct(
        private readonly string $ffmpegBin,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ffmpegBin: (string) config('services.branding.ffmpeg_bin', 'ffmpeg'),
            // Infographics draw more text blocks than the quote stamp — give the
            // filtergraph a little more headroom than the stamper's 90s.
            timeoutSeconds: (int) config('services.branding.infographic_timeout_seconds', 120),
        );
    }

    /**
     * Compose a MULTI-PANEL infographic: a title bar, a grid of panels (each a
     * heading + 0-3 bullets), and an optional footer takeaway banner — all drawn
     * as exact, legible text on top of the text-free background image.
     *
     * @param  array<int,array{heading:string,bullets:array<int,string>}>  $panels
     * @param  array{accent?:string}  $opts  accent = 6-hex (no #) override for the title/footer/rules
     * @return string absolute local path to the composed JPEG
     *
     * @throws RuntimeException
     */
    public function composeInfographic(
        string $backgroundImageUrl,
        string $title,
        array $panels,
        string $footer,
        string $platform,
        int $draftId,
        array $opts = [],
    ): string {
        $panels = $this->sanitisePanels($panels);
        if ($panels === []) {
            throw new RuntimeException('InfographicComposer: no usable panels to render.');
        }

        $aspect = $this->aspectFor($platform);
        $canvas = self::CANVAS[$aspect];
        $accent = $this->resolveAccent($opts['accent'] ?? null);

        $blocks = $this->layoutInfographic($title, $panels, $footer, $canvas, $accent);

        return $this->render($backgroundImageUrl, $canvas, $blocks, $draftId);
    }

    /**
     * Compose a SUMMARY POSTER: a headline + a vertically stacked numbered list
     * of key points, drawn as exact text on the text-free background. This is
     * the single-column degenerate case of an infographic (one "panel" per
     * point, no per-point card chrome).
     *
     * @param  array<int,string>  $points
     * @param  array{accent?:string}  $opts
     * @return string absolute local path to the composed JPEG
     *
     * @throws RuntimeException
     */
    public function composePoster(
        string $backgroundImageUrl,
        string $title,
        array $points,
        string $platform,
        int $draftId,
        array $opts = [],
    ): string {
        $points = array_values(array_filter(array_map(
            static fn ($p) => trim((string) $p),
            $points,
        ), static fn ($p) => $p !== ''));
        if ($points === []) {
            throw new RuntimeException('InfographicComposer: no usable points to render.');
        }

        $aspect = $this->aspectFor($platform);
        $canvas = self::CANVAS[$aspect];
        $accent = $this->resolveAccent($opts['accent'] ?? null);

        $blocks = $this->layoutPoster($title, $points, $canvas, $accent);

        return $this->render($backgroundImageUrl, $canvas, $blocks, $draftId);
    }

    // ─── Layout ──────────────────────────────────────────────────────────────

    /**
     * Geometry for a title bar → panel grid → footer. Returns an ordered list of
     * draw operations the renderer turns into FFmpeg filters. Pure function of
     * the inputs — unit-testable without FFmpeg.
     *
     * @param  array<int,array{heading:string,bullets:array<int,string>}>  $panels
     * @return array<int,array<string,mixed>>
     */
    public function layoutInfographic(string $title, array $panels, string $footer, array $canvas, string $accent): array
    {
        $w = $canvas['w'];
        $h = $canvas['h'];
        $pad = (int) round($w * 0.045);          // outer margin
        $gutter = (int) round($w * 0.025);
        $textW = $w - 2 * $pad;

        // Title bar height is CONTENT-AWARE: pick the title font, wrap it, then
        // size the band to fit however many lines it took (+ vertical padding),
        // so a 2-line title never bleeds past the band onto the cards. Bounded
        // so a pathological title can't eat the whole canvas.
        $titleFont = $this->fitFontSize($title, $textW, (int) round($h * 0.044), (int) round($h * 0.06));
        $titleWrapped = $this->wrapToWidth($title, $textW, $titleFont);
        $titleLines = substr_count($titleWrapped, "\n") + 1;
        $titleLineH = (int) round($titleFont * 1.24);
        $titlePadV = (int) round($h * 0.035);
        $titleH = min((int) round($h * 0.34), $titleLines * $titleLineH + 2 * $titlePadV);

        // Footer band is likewise content-aware (empty footer → no band).
        $footerH = 0;
        $footerFont = 0;
        $footerWrapped = '';
        if ($footer !== '') {
            $footerFont = $this->fitFontSize($footer, $textW, (int) round($h * 0.024), (int) round($h * 0.032));
            $footerWrapped = $this->wrapToWidth($footer, $textW, $footerFont);
            $footerLines = substr_count($footerWrapped, "\n") + 1;
            $footerLineH = (int) round($footerFont * 1.24);
            $footerPadV = (int) round($h * 0.026);
            $footerH = min((int) round($h * 0.22), $footerLines * $footerLineH + 2 * $footerPadV);
        }

        // Grid: prefer 2 columns when >=3 panels (the dense look), 1 column for 2.
        $count = count($panels);
        $cols = $count <= 2 ? 1 : 2;
        if ($count <= 2 && $w > $h) {
            $cols = 2; // landscape with 2 panels reads better side-by-side
        }
        $rows = (int) ceil($count / $cols);

        $gridTop = $titleH + $pad;
        $gridBottom = $h - $footerH - $pad;
        $gridH = max(1, $gridBottom - $gridTop);
        $gridW = $w - 2 * $pad;

        $cellW = (int) floor(($gridW - ($cols - 1) * $gutter) / $cols);
        $cellH = (int) floor(($gridH - ($rows - 1) * $gutter) / $rows);

        $blocks = [];

        // Title bar (accent band) + headline (cream ink), vertically centred.
        $blocks[] = ['type' => 'rect', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $titleH, 'color' => $accent];
        $blocks[] = [
            'type' => 'text',
            'text' => $titleWrapped,
            'x' => $pad,
            'y' => $titlePadV,
            'size' => $titleFont,
            'color' => self::COLOR_CREAM,
            'line_spacing' => (int) round($titleFont * 0.22),
        ];

        // Panels.
        $headingSize = (int) round($cellH * 0.16);
        $headingSize = max(26, min($headingSize, (int) round($h * 0.034)));
        $bulletSize = (int) round($headingSize * 0.72);

        foreach (array_values($panels) as $i => $panel) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $cx = $pad + $col * ($cellW + $gutter);
            $cy = $gridTop + $row * ($cellH + $gutter);

            // Card background + a top accent rule.
            $blocks[] = ['type' => 'rect', 'x' => $cx, 'y' => $cy, 'w' => $cellW, 'h' => $cellH, 'color' => self::COLOR_PANEL];
            $blocks[] = ['type' => 'rect', 'x' => $cx, 'y' => $cy, 'w' => $cellW, 'h' => max(4, (int) round($cellH * 0.012)), 'color' => $accent];

            $innerPad = (int) round($cellW * 0.07);
            $textX = $cx + $innerPad;
            $textW = $cellW - 2 * $innerPad;
            $cursorY = $cy + $innerPad + (int) round($cellH * 0.04);

            // Numbered chip + heading.
            $heading = trim((string) $panel['heading']);
            if ($heading !== '') {
                $headingFit = $this->fitFontSize($heading, $textW, $bulletSize, $headingSize);
                $blocks[] = [
                    'type' => 'text',
                    'text' => sprintf('%d  %s', $i + 1, $this->wrapToWidth($heading, $textW, $headingFit)),
                    'x' => $textX,
                    'y' => $cursorY,
                    'size' => $headingFit,
                    'color' => self::COLOR_INK,
                    'line_spacing' => (int) round($headingFit * 0.2),
                ];
                $headingLines = substr_count($this->wrapToWidth($heading, $textW, $headingFit), "\n") + 1;
                $cursorY += $headingLines * (int) round($headingFit * 1.22) + (int) round($cellH * 0.05);
            }

            // Bullets.
            foreach ($panel['bullets'] as $bullet) {
                $bullet = trim((string) $bullet);
                if ($bullet === '') {
                    continue;
                }
                $wrapped = $this->wrapToWidth('•  '.$bullet, $textW, $bulletSize);
                $blocks[] = [
                    'type' => 'text',
                    'text' => $wrapped,
                    'x' => $textX,
                    'y' => $cursorY,
                    'size' => $bulletSize,
                    'color' => self::COLOR_MUTED,
                    'line_spacing' => (int) round($bulletSize * 0.25),
                ];
                $lines = substr_count($wrapped, "\n") + 1;
                $cursorY += $lines * (int) round($bulletSize * 1.3) + (int) round($bulletSize * 0.35);

                // Stop drawing bullets that would overflow the card.
                if ($cursorY > $cy + $cellH - $innerPad) {
                    break;
                }
            }
        }

        // Footer takeaway banner (content-aware height computed above).
        if ($footer !== '' && $footerH > 0) {
            $fy = $h - $footerH;
            $blocks[] = ['type' => 'rect', 'x' => 0, 'y' => $fy, 'w' => $w, 'h' => $footerH, 'color' => $accent];
            $footerPadV = (int) round($h * 0.026);
            $blocks[] = [
                'type' => 'text',
                'text' => $footerWrapped,
                'x' => $pad,
                'y' => $fy + $footerPadV,
                'size' => $footerFont,
                'color' => self::COLOR_CREAM,
                'line_spacing' => (int) round($footerFont * 0.22),
            ];
        }

        return $blocks;
    }

    /**
     * Geometry for a single-headline poster: title band + a stacked numbered
     * list. Pure function — unit-testable without FFmpeg.
     *
     * @param  array<int,string>  $points
     * @return array<int,array<string,mixed>>
     */
    public function layoutPoster(string $title, array $points, array $canvas, string $accent): array
    {
        $w = $canvas['w'];
        $h = $canvas['h'];
        $pad = (int) round($w * 0.07);
        $titleH = (int) round($h * 0.2);

        $blocks = [];

        // Title band + headline.
        $blocks[] = ['type' => 'rect', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $titleH, 'color' => $accent];
        $titleFont = $this->fitFontSize($title, $w - 2 * $pad, (int) round($h * 0.06), (int) round($titleH * 0.4));
        $blocks[] = [
            'type' => 'text',
            'text' => $this->wrapToWidth($title, $w - 2 * $pad, $titleFont),
            'x' => $pad,
            'y' => (int) round($titleH * 0.3),
            'size' => $titleFont,
            'color' => self::COLOR_CREAM,
            'line_spacing' => (int) round($titleFont * 0.22),
        ];

        // Points: evenly distribute the space below the title band.
        $listTop = $titleH + (int) round($h * 0.06);
        $listH = $h - $listTop - $pad;
        $rowH = (int) floor($listH / max(1, count($points)));
        $pointSize = max(30, min((int) round($rowH * 0.28), (int) round($h * 0.04)));

        foreach (array_values($points) as $i => $point) {
            $py = $listTop + $i * $rowH;
            // Number chip.
            $chip = (int) round($pointSize * 1.5);
            $blocks[] = ['type' => 'rect', 'x' => $pad, 'y' => $py, 'w' => $chip, 'h' => $chip, 'color' => $accent];
            $blocks[] = [
                'type' => 'text',
                'text' => (string) ($i + 1),
                'x' => $pad + (int) round($chip * 0.33),
                'y' => $py + (int) round($chip * 0.16),
                'size' => $pointSize,
                'color' => self::COLOR_CREAM,
                'line_spacing' => 0,
            ];
            // Point text, to the right of the chip.
            $textX = $pad + $chip + (int) round($w * 0.03);
            $textW = $w - $textX - $pad;
            $wrapped = $this->wrapToWidth($point, $textW, $pointSize);
            $blocks[] = [
                'type' => 'text',
                'text' => $wrapped,
                'x' => $textX,
                'y' => $py + (int) round($chip * 0.1),
                'size' => $pointSize,
                'color' => self::COLOR_INK,
                'line_spacing' => (int) round($pointSize * 0.25),
            ];
        }

        return $blocks;
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    /**
     * Run the FFmpeg filtergraph: scale-crop the background to the canvas, then
     * overlay every rect + drawtext block in order. Text is written to per-block
     * textfiles (so quotes / colons / special chars in the copy can never break
     * the filtergraph or inject options).
     *
     * @param  array<int,array<string,mixed>>  $blocks
     */
    private function render(string $backgroundImageUrl, array $canvas, array $blocks, int $draftId): string
    {
        $workDir = $this->workDir($draftId);
        $fontPath = $this->resolveFont();
        $fontPathEsc = $this->ffmpegEscapeFilterPath($fontPath);

        $sourcePath = $workDir.'/bg.bin';
        $this->downloadTo($backgroundImageUrl, $sourcePath);

        $w = $canvas['w'];
        $h = $canvas['h'];

        // Background scaled-cropped to fill the canvas, then a semi-transparent
        // cream SCRIM over the whole frame so drawn text sits on a calm,
        // high-contrast field rather than fighting a busy AI illustration. The
        // scrim is a drawbox with an alpha-suffixed colour (0xRRGGBB@a) — a
        // reliable, alpha-channel-independent way to lighten an opaque JPEG
        // (colorchannelmixer=aa is a no-op on an image with no alpha plane).
        $chain = [
            "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h}[bg];",
            // Scrim: cream at 55% opacity across the full frame.
            "[bg]drawbox=x=0:y=0:w={$w}:h={$h}:color=0x".self::COLOR_CREAM."@0.55:t=fill[base0];",
        ];

        $lastLabel = 'base0';
        $textFileIndex = 0;

        foreach ($blocks as $bi => $block) {
            $next = 'step'.$bi;
            if (($block['type'] ?? '') === 'rect') {
                $x = (int) $block['x'];
                $y = (int) $block['y'];
                $bw = (int) $block['w'];
                $bh = (int) $block['h'];
                $color = $this->hex($block['color']);
                $chain[] = "[{$lastLabel}]drawbox=x={$x}:y={$y}:w={$bw}:h={$bh}:color=0x{$color}:t=fill[{$next}];";
            } elseif (($block['type'] ?? '') === 'text') {
                $text = (string) $block['text'];
                $textFile = $workDir.'/t'.$textFileIndex.'.txt';
                $textFileIndex++;
                file_put_contents($textFile, $text);
                $textFileEsc = $this->ffmpegEscapeFilterPath($textFile);

                $x = (int) $block['x'];
                $y = (int) $block['y'];
                $size = (int) $block['size'];
                $color = $this->hex($block['color']);
                $ls = (int) ($block['line_spacing'] ?? 0);

                $chain[] = "[{$lastLabel}]drawtext=fontfile='{$fontPathEsc}':textfile='{$textFileEsc}':"
                    ."fontcolor=0x{$color}:fontsize={$size}:line_spacing={$ls}:x={$x}:y={$y}[{$next}];";
            } else {
                continue;
            }
            $lastLabel = $next;
        }

        // Terminate the graph: a null passthrough relabels the last step to
        // [final] (the -map target). No trailing ';' on the final filter.
        $chain[] = "[{$lastLabel}]null[final]";
        $filterChain = implode('', $chain);

        $outputPath = $workDir.'/infographic.jpg';

        $args = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $sourcePath,
            '-filter_complex', $filterChain,
            '-map', '[final]',
            '-frames:v', '1',
            '-q:v', '2',
            $outputPath,
        ];

        try {
            $proc = new Process($args);
            $proc->setTimeout($this->timeoutSeconds);
            $proc->mustRun();
        } catch (ProcessFailedException $e) {
            $stderr = trim((string) $e->getProcess()?->getErrorOutput());
            throw new RuntimeException(
                'InfographicComposer FFmpeg failed: '.substr($stderr, 0, 400),
                0,
                $e,
            );
        }

        if (! is_file($outputPath) || filesize($outputPath) < 1024) {
            throw new RuntimeException("InfographicComposer: output {$outputPath} missing or empty.");
        }

        return $outputPath;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Normalise the panel list: trim heading + bullets, drop empties, cap
     * bullets at 3/panel and panels at 6 (what reads on a phone).
     *
     * @param  array<int,mixed>  $panels
     * @return array<int,array{heading:string,bullets:array<int,string>}>
     */
    private function sanitisePanels(array $panels): array
    {
        $out = [];
        foreach ($panels as $p) {
            if (! is_array($p)) {
                continue;
            }
            $heading = trim((string) ($p['heading'] ?? ''));
            $bullets = [];
            foreach (is_array($p['bullets'] ?? null) ? $p['bullets'] : [] as $b) {
                $b = trim((string) $b);
                if ($b !== '') {
                    $bullets[] = $b;
                }
                if (count($bullets) >= 3) {
                    break;
                }
            }
            if ($heading === '' && $bullets === []) {
                continue;
            }
            $out[] = ['heading' => $heading !== '' ? $heading : 'Key point', 'bullets' => $bullets];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /**
     * Word-wrap text to an approximate pixel width given a font size, inserting
     * \n. Uses an average glyph-width factor (~0.55em for the sans we render);
     * good enough for safe-zone-margined cards without measuring real glyphs
     * (same pragmatic approach as BrandImageStamper::wrapQuote).
     */
    public function wrapToWidth(string $text, int $pixelWidth, int $fontSize): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        if ($text === '') {
            return '';
        }
        $charsPerLine = max(6, (int) floor($pixelWidth / max(1, $fontSize * 0.55)));

        return wordwrap($text, $charsPerLine, "\n", true);
    }

    /**
     * Shrink the font size until the text's longest word fits the width and the
     * whole string isn't absurdly tall. Returns a size in [min, max]. Keeps long
     * single words (e.g. "personalisation") from overflowing a narrow card.
     */
    public function fitFontSize(string $text, int $pixelWidth, int $minSize, int $maxSize): int
    {
        $text = trim($text);
        if ($text === '' || $pixelWidth <= 0) {
            return max($minSize, min($maxSize, $minSize));
        }
        $longest = 0;
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $longest = max($longest, mb_strlen($word));
        }
        $longest = max(1, $longest);

        // Size at which the longest word still fits one line (glyph ~0.55em).
        $fitForLongest = (int) floor($pixelWidth / ($longest * 0.55));
        $size = min($maxSize, max($minSize, $fitForLongest));

        return max($minSize, min($maxSize, $size));
    }

    private function aspectFor(string $platform): string
    {
        return match (strtolower($platform)) {
            'tiktok', 'threads', 'pinterest' => 'portrait',
            'youtube' => 'landscape',
            default => 'square',
        };
    }

    /** Validate a 6-hex accent (no #); fall back to deep teal. */
    private function resolveAccent(?string $accent): string
    {
        $a = ltrim((string) $accent, '#');
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $a) === 1) {
            return strtoupper($a);
        }

        return self::COLOR_TEAL_DEEP;
    }

    /** Normalise a stored 6-hex token to upper, defaulting to ink on garbage. */
    private function hex(mixed $value): string
    {
        $v = ltrim((string) $value, '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $v) === 1 ? strtoupper($v) : self::COLOR_INK;
    }

    /**
     * FFmpeg filtergraph values inside single-quoted strings need backslash
     * escapes for \, :, '. Forward-slash the path so Windows backslashes don't
     * bite. Mirrors BrandImageStamper::ffmpegEscapeFilterPath.
     */
    private function ffmpegEscapeFilterPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return str_replace([':', "'"], ['\\:', "\\'"], $normalized);
    }

    /**
     * Locate a usable TTF. Same candidate order as BrandImageStamper so both
     * renderers pick the identical font on every host (bundled Inter → Nix Noto
     * → system fallbacks). Throws when none is found.
     */
    private function resolveFont(): string
    {
        $candidates = array_filter([
            public_path('brand/fonts/Inter-SemiBold.ttf'),
            public_path('brand/fonts/Inter-Regular.ttf'),
            ...glob('/nix/store/*/share/fonts/noto/NotoSans-SemiBold.ttf') ?: [],
            ...glob('/nix/store/*/share/fonts/noto/NotoSans-Regular.ttf') ?: [],
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/arial.ttf',
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            'InfographicComposer: no usable font found. '
            .'Drop Inter-SemiBold.ttf into public/brand/fonts/ or install noto-fonts on the host.'
        );
    }

    private function workDir(int $draftId): string
    {
        $base = storage_path('app/branding/'.$draftId.'-info-'.Str::random(8));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new RuntimeException("InfographicComposer: failed to create work dir {$base}");
        }

        return $base;
    }

    private function downloadTo(string $url, string $path): void
    {
        $bytes = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 30],
            'https' => ['timeout' => 30],
        ]));
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("InfographicComposer: failed to download background {$url}");
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("InfographicComposer: failed to write {$path}");
        }
    }
}
