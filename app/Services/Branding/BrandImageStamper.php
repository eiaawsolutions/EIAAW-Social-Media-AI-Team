<?php

namespace App\Services\Branding;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Stamps a FAL-generated still with the EIAAW brand layer:
 *
 *   1. Per-platform aspect-aware composition:
 *      - 1:1  → side-card layout (image left 65%, quote panel right 35%)
 *      - 9:16 → bottom-quote layout (image top 75%, quote panel bottom 25%)
 *      - 16:9 → bottom-banner layout (image top 80%, quote banner bottom 20%)
 *   2. Quote text rendered in Inter at 4-6% of the canvas width, near-black
 *      ink (#0F1A1D) on warm cream (#FAF7F2), wrapped to fit panel width.
 *   3. Logo (public/brand/logo-full.png) bottom-right of the quote panel
 *      at 8-10% of canvas width.
 *   4. "Powered by EIAAW Solutions" caption in JetBrains Mono uppercase
 *      below the logo.
 *
 * Implementation: pure FFmpeg `drawtext` + `overlay` filter graph executed
 * via Symfony Process (NEVER shell-escaped strings — every value is passed
 * as a separate argv item so a malicious quote can't inject filtergraph
 * options).
 *
 * Output: written to a temp file under storage/app/branding/ (not yet
 * uploaded — caller is responsible for handing the local path back to
 * Blotato re-host).
 *
 * Failure: throws RuntimeException — caller catches and falls back to
 * the unbranded raw FAL URL.
 */
class BrandImageStamper
{
    /** EIAAW palette tokens — must match references/eiaaw-design-system.md */
    private const COLOR_INK = '0F1A1D';
    private const COLOR_CREAM = 'FAF7F2';
    private const COLOR_TEAL_DEEP = '11766A';

    /** Aspect-driven canvas + panel geometry. Pixel sizes match FAL's output. */
    private const LAYOUTS = [
        // square: side card layout. Total 1080x1080. Image 700px wide on left,
        // quote panel 380px on right with 32px margin each side.
        'square' => [
            'canvas_w' => 1080, 'canvas_h' => 1080,
            'image_x' => 0,     'image_y' => 0,
            'image_w' => 700,   'image_h' => 1080,
            'panel_x' => 700,   'panel_y' => 0,
            'panel_w' => 380,   'panel_h' => 1080,
            'quote_font_size' => 38,
            'quote_max_chars_per_line' => 18,
            'quote_anchor_y' => 280,
            'logo_anchor' => 'panel_bottom_left',
        ],
        // portrait/9:16: bottom-quote band. Canvas 1080x1920. Image fills top
        // 1440px; quote panel is bottom 480px with safe-zone awareness.
        'portrait' => [
            'canvas_w' => 1080, 'canvas_h' => 1920,
            'image_x' => 0,     'image_y' => 0,
            'image_w' => 1080,  'image_h' => 1440,
            'panel_x' => 0,     'panel_y' => 1440,
            'panel_w' => 1080,  'panel_h' => 480,
            'quote_font_size' => 56,
            'quote_max_chars_per_line' => 28,
            'quote_anchor_y' => 1530,
            'logo_anchor' => 'panel_bottom_left',
        ],
        // landscape/16:9: bottom-banner. Canvas 1920x1080. Image top 864px;
        // quote banner bottom 216px.
        'landscape' => [
            'canvas_w' => 1920, 'canvas_h' => 1080,
            'image_x' => 0,     'image_y' => 0,
            'image_w' => 1920,  'image_h' => 864,
            'panel_x' => 0,     'panel_y' => 864,
            'panel_w' => 1920,  'panel_h' => 216,
            'quote_font_size' => 44,
            'quote_max_chars_per_line' => 60,
            'quote_anchor_y' => 920,
            'logo_anchor' => 'panel_bottom_right',
        ],
    ];

    public function __construct(
        private readonly string $ffmpegBin,
        private readonly int $timeoutSeconds = 90,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ffmpegBin: (string) config('services.branding.ffmpeg_bin', 'ffmpeg'),
            timeoutSeconds: (int) config('services.branding.ffmpeg_timeout_seconds', 90),
        );
    }

    /**
     * Stamp a remote image (FAL CDN URL) with the quote + logo. Downloads
     * the source, runs the FFmpeg filtergraph, returns the local path of
     * the stamped JPEG.
     *
     * @return string  absolute local path to the stamped image
     *
     * @throws RuntimeException
     */
    public function stamp(string $sourceImageUrl, string $quote, string $platform, int $draftId): string
    {
        $layout = self::LAYOUTS[$this->layoutFor($platform)];

        $workDir = $this->workDir($draftId);

        // 1. Download FAL image to local file (FFmpeg accepts http(s) URLs
        // directly but a local file gives faster/more deterministic IO and
        // lets us validate it's an image before passing to FFmpeg).
        $sourcePath = $workDir . '/source.bin';
        $this->downloadTo($sourceImageUrl, $sourcePath);

        // 2. Resolve overlay assets (logo + font) at known repo paths.
        $logoPath = public_path('brand/logo-full.png');
        if (! is_file($logoPath)) {
            throw new RuntimeException("BrandImageStamper: logo missing at {$logoPath}");
        }
        $fontPath = $this->resolveFont();

        // 3. Word-wrap the quote so it fits the panel without measuring
        // glyph widths (Inter at our sizes is roughly even-width enough that
        // a char count works). FFmpeg drawtext's textfile= reads literal lines.
        $wrappedQuote = $this->wrapQuote($quote, $layout['quote_max_chars_per_line']);
        $quoteFile = $workDir . '/quote.txt';
        file_put_contents($quoteFile, $wrappedQuote);

        $tagFile = $workDir . '/tag.txt';
        file_put_contents($tagFile, 'POWERED BY EIAAW SOLUTIONS');

        $outputPath = $workDir . '/stamped.jpg';

        // 4. Build the filter graph. Steps:
        //    [0]   FAL image, scaled-and-cropped to fill image_w x image_h
        //    [bg]  cream-colour canvas at full canvas_w x canvas_h
        //    [bg+0] overlay image at (image_x, image_y) → tmp1
        //    [tmp1+text] drawtext quote at panel_x+padding, quote_anchor_y → tmp2
        //    [tmp2+logo] overlay scaled logo near the panel bottom → tmp3
        //    [tmp3+tagtext] drawtext "POWERED BY EIAAW SOLUTIONS" → final
        $filterChain = $this->buildFilterChain($layout, $logoPath, $fontPath, $quoteFile, $tagFile);

        $args = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $sourcePath,
            '-i', $logoPath,
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
                'BrandImageStamper FFmpeg failed: ' . substr($stderr, 0, 400),
                0,
                $e,
            );
        }

        if (! is_file($outputPath) || filesize($outputPath) < 1024) {
            throw new RuntimeException("BrandImageStamper: output {$outputPath} missing or empty.");
        }

        return $outputPath;
    }

    private function buildFilterChain(
        array $layout,
        string $logoPath,
        string $fontPath,
        string $quoteFile,
        string $tagFile,
    ): string {
        // Logo width = ~12% of canvas for portrait/landscape, ~20% for square panel.
        $logoW = $layout['canvas_w'] >= 1920 ? 220 : 180;

        // Drawtext positions for quote (top-left of textbox).
        $quoteX = $layout['panel_x'] + 56;
        $quoteY = $layout['quote_anchor_y'];

        // Logo + tag positions inside the panel.
        if ($layout['logo_anchor'] === 'panel_bottom_left') {
            $logoX = $layout['panel_x'] + 48;
            $logoY = $layout['panel_y'] + $layout['panel_h'] - 140;
            $tagX = $layout['panel_x'] + 48 + $logoW + 24;
            $tagY = $logoY + 50;
        } else {
            $logoX = $layout['panel_x'] + $layout['panel_w'] - $logoW - 56;
            $logoY = $layout['panel_y'] + $layout['panel_h'] - 110;
            $tagX = $layout['panel_x'] + 56;
            $tagY = $layout['panel_y'] + $layout['panel_h'] - 80;
        }

        $cream = self::COLOR_CREAM;
        $ink = self::COLOR_INK;
        $teal = self::COLOR_TEAL_DEEP;
        $canvasW = $layout['canvas_w'];
        $canvasH = $layout['canvas_h'];
        $imageW = $layout['image_w'];
        $imageH = $layout['image_h'];
        $imageX = $layout['image_x'];
        $imageY = $layout['image_y'];
        $quoteFontSize = $layout['quote_font_size'];

        // FFmpeg filter graph. Filenames and colours are hex strings, never
        // user input — so straight string interpolation is safe here.
        // Quote/tag text is read from a file via textfile= which sidesteps
        // any escaping concerns about quotes/special chars in the quote.
        $quoteFileEsc = $this->ffmpegEscapeFilterPath($quoteFile);
        $tagFileEsc = $this->ffmpegEscapeFilterPath($tagFile);
        $fontPathEsc = $this->ffmpegEscapeFilterPath($fontPath);

        return implode('', [
            // Background canvas: warm cream at canvas dimensions.
            "color=c=0x{$cream}:s={$canvasW}x{$canvasH}:d=1[bg];",

            // FAL image scaled-cropped to image_w x image_h.
            "[0:v]scale={$imageW}:{$imageH}:force_original_aspect_ratio=increase,crop={$imageW}:{$imageH}[img];",

            // Composite image onto bg.
            "[bg][img]overlay={$imageX}:{$imageY}[bg2];",

            // Subtle 2-pixel deep teal accent line between image and quote panel.
            // Drawn by overlaying a 100% opacity color filter at the seam.
            "color=c=0x{$teal}:s={$canvasW}x4:d=1[seam];",
            "[bg2][seam]overlay=0:" . ($imageY + $imageH - 2) . "[bg3];",

            // Quote text via drawtext + textfile (avoids quote-escaping).
            "[bg3]drawtext=fontfile='{$fontPathEsc}':textfile='{$quoteFileEsc}':"
                . "fontcolor=0x{$ink}:fontsize={$quoteFontSize}:line_spacing=14:"
                . "x={$quoteX}:y={$quoteY}[bg4];",

            // Logo overlay: scale to logoW preserving aspect, then composite.
            "[1:v]scale={$logoW}:-1[logo];",
            "[bg4][logo]overlay={$logoX}:{$logoY}[bg5];",

            // "POWERED BY EIAAW SOLUTIONS" tag.
            "[bg5]drawtext=fontfile='{$fontPathEsc}':textfile='{$tagFileEsc}':"
                . "fontcolor=0x{$teal}:fontsize=18:letter_spacing=2:"
                . "x={$tagX}:y={$tagY}[final]",
        ]);
    }

    /**
     * FFmpeg's filter syntax interprets `:`, `\`, `'`, and `,` as
     * separators inside option values. textfile= and fontfile= values
     * must escape these. Windows paths with backslashes especially.
     * See: https://ffmpeg.org/ffmpeg-filters.html#Notes-on-filtergraph-escaping
     */
    private function ffmpegEscapeFilterPath(string $path): string
    {
        // FFmpeg filtergraph values inside single-quoted strings only need
        // backslash-escapes for: \, :, '. We use forward slashes regardless
        // of OS (FFmpeg accepts them on Windows too) to dodge the worst case.
        $normalized = str_replace('\\', '/', $path);
        return str_replace([':', "'"], ['\\:', "\\'"], $normalized);
    }

    private function layoutFor(string $platform): string
    {
        return match (strtolower($platform)) {
            'tiktok', 'threads', 'pinterest' => 'portrait',
            'youtube' => 'landscape',
            default => 'square',
        };
    }

    private function wrapQuote(string $quote, int $maxCharsPerLine): string
    {
        $clean = trim($quote);
        return wordwrap($clean, $maxCharsPerLine, "\n", false);
    }

    /**
     * Locate a usable system font. Order:
     *   1. App-bundled (public/brand/fonts/Inter-SemiBold.ttf if operator dropped one)
     *   2. Nix store noto-fonts (Linux/Railway)
     *   3. Common system fallbacks
     *
     * Returns the absolute path or throws.
     */
    private function resolveFont(): string
    {
        $candidates = array_filter([
            public_path('brand/fonts/Inter-SemiBold.ttf'),
            public_path('brand/fonts/Inter-Regular.ttf'),
            // Nix-installed Noto on Railway. Glob to absorb hash directories.
            ...glob('/nix/store/*/share/fonts/noto/NotoSans-Regular.ttf') ?: [],
            ...glob('/nix/store/*/share/fonts/noto/NotoSans-SemiBold.ttf') ?: [],
            // Common Linux paths.
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans-Bold.ttf',
            // Windows.
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/arial.ttf',
            // macOS.
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }

        throw new RuntimeException(
            'BrandImageStamper: no usable font found. '
            . 'Drop Inter-Regular.ttf into public/brand/fonts/ or install noto-fonts on the host.'
        );
    }

    private function workDir(int $draftId): string
    {
        $base = storage_path('app/branding/' . $draftId . '-' . Str::random(8));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new RuntimeException("BrandImageStamper: failed to create work dir {$base}");
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
            throw new RuntimeException("BrandImageStamper: failed to download source {$url}");
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("BrandImageStamper: failed to write {$path}");
        }
    }
}
