<?php

namespace App\Services\Imagery;

use Illuminate\Support\Facades\Log;

/**
 * Looks at the PIXELS of a rendered image and decides whether it is a real
 * piece of creative or an empty/degenerate canvas.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every other gate in the pipeline reasons about the draft's *text and
 * metadata*. ComplianceAgent's eight checks read the caption, the hashtags,
 * the grounding sources; PlatformRules asks only "is an asset_url present?";
 * MediaComplianceChecker measures bytes/dimensions/aspect. Nothing had ever
 * opened the image.
 *
 * That gap shipped real posts. The poster/infographic path deliberately asks
 * the image model for a TEXT-FREE background ("render ABSOLUTELY NO TEXT of
 * any kind") because InfographicComposer draws the copy afterwards with
 * FFmpeg. When the compose step is skipped or fails, the soft-fallback
 * publishes that background — a blank cream card with a coloured band. It has
 * a valid URL, valid dimensions, valid bytes, and a perfectly compliant
 * caption, so all eight checks passed and six posts went live empty
 * (scheduled_posts #541-#546, 2026-07-29/30).
 *
 * The metrics below are deliberately simple, deterministic and dependency-free
 * (ext-gd only — no vision model, no network, ~5ms on a downsampled frame):
 *
 *   ink_coverage   fraction of pixels that differ perceptibly from the modal
 *                  (background) tone. Text, subjects and illustration all
 *                  produce ink; an empty designed card produces almost none.
 *                  This is the primary blank signal.
 *   edge_density   fraction of pixels with a hard local luminance step. Flat
 *                  fields and soft gradients score ~0; glyph edges score high.
 *                  Separates "blank card with a coloured band" (large ink from
 *                  the band, no edges) from real creative.
 *   dominant_share share of the single most common tone bucket.
 *   distinct_tones number of tone buckets holding a non-trivial share.
 *
 * Verdicts are advisory data; the CALLER decides what blocks. The gate lives in
 * ComplianceAgent's media_quality check (check 9).
 *
 * Thresholds live in config('services.branding.media_vetting') so they can be
 * retuned from the environment without a deploy.
 */
class RenderedMediaInspector
{
    public const VERDICT_OK = 'ok';

    public const VERDICT_BLANK = 'blank';

    public const VERDICT_LOW_DETAIL = 'low_detail';

    public const VERDICT_UNREADABLE = 'unreadable';

    /** Longest edge of the working copy. Big enough to keep glyph edges, small enough to be fast. */
    private const SAMPLE_EDGE = 320;

    /** Luminance delta (0-255) at which a pixel counts as "ink" against the modal tone. */
    private const INK_DELTA = 26;

    /** Local luminance step (0-255) at which a pixel counts as an edge. */
    private const EDGE_DELTA = 20;

    /** Tone buckets: 256 luminance levels → 32 buckets of 8. */
    private const TONE_SHIFT = 3;

    /**
     * Inspect a LOCAL image file.
     *
     * @return array{
     *   ok: bool,
     *   verdict: string,
     *   reason: string,
     *   metrics: array<string, float|int>
     * }
     */
    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) === 0) {
            return $this->unreadable('The image file could not be read on the server.');
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $this->unreadable('The image file could not be read on the server.');
        }

        $im = @imagecreatefromstring($raw);
        if ($im === false) {
            return $this->unreadable('The file is not a decodable image (corrupt or not an image).');
        }

        return $this->evaluate($im);
    }

    /**
     * Inspect a REMOTE image. Network problems are reported as `unreadable`
     * with ok=false — the caller decides whether that blocks (a dead asset URL
     * is a real defect) or degrades to a warning (a flaky fetch is not).
     *
     * @return array{ok: bool, verdict: string, reason: string, metrics: array<string, float|int>}
     */
    public function inspectUrl(string $url, int $timeoutSeconds = 20, int $maxBytes = 25_000_000): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mediavet_');
        if ($tmp === false) {
            return $this->unreadable('Could not allocate a temp file to inspect the image.');
        }

        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'ignore_errors' => true],
                'https' => ['timeout' => $timeoutSeconds, 'follow_location' => 1, 'ignore_errors' => true],
            ]);

            $bytes = @file_get_contents($url, false, $ctx, 0, $maxBytes);
            $status = $this->statusFromHeaders($http_response_header ?? []);

            if ($status >= 400) {
                return $this->unreadable(sprintf('The image URL returned HTTP %d — the media is not retrievable.', $status));
            }
            if ($bytes === false || $bytes === '') {
                return $this->unreadable('The image could not be downloaded for inspection.');
            }

            if (file_put_contents($tmp, $bytes) === false) {
                return $this->unreadable('Could not buffer the image for inspection.');
            }

            return $this->inspect($tmp);
        } catch (\Throwable $e) {
            Log::info('RenderedMediaInspector: fetch failed', [
                'url' => substr($url, 0, 160),
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return $this->unreadable('The image could not be downloaded for inspection.');
        } finally {
            @unlink($tmp);
        }
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param  \GdImage  $im
     * @return array{ok: bool, verdict: string, reason: string, metrics: array<string, float|int>}
     */
    private function evaluate($im): array
    {
        $fullW = imagesx($im);
        $fullH = imagesy($im);
        if ($fullW < 1 || $fullH < 1) {
            return $this->unreadable('The image reports a zero dimension.');
        }

        // Downsample to a fixed working size. imagescale's bilinear resampling
        // preserves glyph edges well enough for edge_density while cutting the
        // pixel loop from millions to ~100k.
        $scale = self::SAMPLE_EDGE / max($fullW, $fullH);
        $work = $im;
        if ($scale < 1.0) {
            $scaled = @imagescale($im, max(1, (int) round($fullW * $scale)), max(1, (int) round($fullH * $scale)));
            if ($scaled !== false) {
                $work = $scaled;
            }
        }

        // Palette images make imagecolorat return an index, not a colour.
        if (! imageistruecolor($work)) {
            @imagepalettetotruecolor($work);
        }

        $w = imagesx($work);
        $h = imagesy($work);

        // ── Pass 1: luminance map + tone histogram ──
        $lum = [];
        $hist = array_fill(0, 256 >> self::TONE_SHIFT, 0);
        $sum = 0;

        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($work, $x, $y);
                $l = (int) (
                    0.299 * (($rgb >> 16) & 0xFF)
                    + 0.587 * (($rgb >> 8) & 0xFF)
                    + 0.114 * ($rgb & 0xFF)
                );
                $row[$x] = $l;
                $hist[$l >> self::TONE_SHIFT]++;
                $sum += $l;
            }
            $lum[$y] = $row;
        }

        $total = $w * $h;

        // Modal tone bucket = the background. Its centre is the reference
        // every other pixel is measured against.
        $dominantBucket = 0;
        $dominantCount = 0;
        $distinctTones = 0;
        foreach ($hist as $bucket => $count) {
            if ($count > $dominantCount) {
                $dominantCount = $count;
                $dominantBucket = $bucket;
            }
            if ($count / $total >= 0.002) {
                $distinctTones++;
            }
        }
        $backgroundLum = ($dominantBucket << self::TONE_SHIFT) + (1 << (self::TONE_SHIFT - 1));

        // ── Pass 2: ink + edges ──
        $ink = 0;
        $edges = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $l = $lum[$y][$x];

                if (abs($l - $backgroundLum) > self::INK_DELTA) {
                    $ink++;
                }

                $step = 0;
                if ($x + 1 < $w) {
                    $step = max($step, abs($l - $lum[$y][$x + 1]));
                }
                if ($y + 1 < $h) {
                    $step = max($step, abs($l - $lum[$y + 1][$x]));
                }
                if ($step > self::EDGE_DELTA) {
                    $edges++;
                }
            }
        }

        return $this->verdictFor([
            'width' => $fullW,
            'height' => $fullH,
            'ink_coverage' => round($ink / $total, 5),
            'edge_density' => round($edges / $total, 5),
            'dominant_share' => round($dominantCount / $total, 5),
            'distinct_tones' => $distinctTones,
            'mean_luminance' => round($sum / $total, 2),
        ]);
    }

    /**
     * Turn metrics into a verdict.
     *
     * Two distinct failure shapes, because a blank POSTER background is NOT a
     * uniformly flat image — it carries a coloured header band, and that band
     * alone puts ink_coverage above 20% while the surface stays devoid of
     * detail. So ink_coverage cannot be the discriminator here:
     *
     *   blank       almost nothing differs from the background tone at all
     *               (a genuinely solid canvas).
     *   low_detail  there IS a second tone (the band) but essentially no local
     *               structure anywhere — no glyphs, no subject, no texture.
     *               This is the exact signature of the text-free scaffold, and
     *               edge_density is what catches it.
     *
     * CALIBRATION (measured 2026-07-30, see MediaQualityVettingTest):
     *
     *   edge_density   worst-case      best-case
     *   ─────────────  ──────────────  ─────────────────────────────
     *   blank backgrounds shipped live  0.0032 … 0.0171  (n=6, the #541-#546 assets)
     *   same bg + composited poster text 0.0727 … 0.0853 (n=3)
     *   branded artifacts in production  0.1181 … 0.1439 (n=4)
     *   operator photo upload            0.1928          (n=1)
     *
     * A 4.25x gap separates the worst bad from the best good. The floor is set
     * at the geometric midpoint (0.035) — 2.0x clear of both sides. Re-encoding
     * a blank PNG to q:v 2 JPEG moves it by 0.0003, so the metric does not drift
     * with format.
     *
     * dominant_share deliberately does NOT gate this rule: measured goods span
     * 0.37-0.71 and measured bads span 0.53-0.77, so it carries no signal, and
     * an earlier draft that gated on it would have passed two of the six blanks.
     *
     * @param  array<string, float|int>  $m
     * @return array{ok: bool, verdict: string, reason: string, metrics: array<string, float|int>}
     */
    private function verdictFor(array $m): array
    {
        $cfg = (array) config('services.branding.media_vetting', []);
        $minInk = (float) ($cfg['min_ink_coverage'] ?? 0.015);
        $minEdge = (float) ($cfg['min_edge_density'] ?? 0.035);

        if ($m['ink_coverage'] < $minInk) {
            return [
                'ok' => false,
                'verdict' => self::VERDICT_BLANK,
                'reason' => sprintf(
                    'The image is effectively blank — only %.2f%% of it differs from the background colour (needs %.2f%%). '
                    .'This is an empty canvas, not finished creative.',
                    $m['ink_coverage'] * 100,
                    $minInk * 100,
                ),
                'metrics' => $m,
            ];
        }

        if ($m['edge_density'] < $minEdge) {
            return [
                'ok' => false,
                'verdict' => self::VERDICT_LOW_DETAIL,
                'reason' => sprintf(
                    'The image has no legible content — only %.2f%% of it carries any detail or lettering (needs %.2f%%). '
                    .'This is an empty designed background: the headline and points were never drawn onto it.',
                    $m['edge_density'] * 100,
                    $minEdge * 100,
                ),
                'metrics' => $m,
            ];
        }

        return [
            'ok' => true,
            'verdict' => self::VERDICT_OK,
            'reason' => sprintf(
                'Image carries real content (%.1f%% ink, %.1f%% edge detail, %d tones).',
                $m['ink_coverage'] * 100,
                $m['edge_density'] * 100,
                $m['distinct_tones'],
            ),
            'metrics' => $m,
        ];
    }

    /**
     * @return array{ok: bool, verdict: string, reason: string, metrics: array<string, float|int>}
     */
    private function unreadable(string $reason): array
    {
        return ['ok' => false, 'verdict' => self::VERDICT_UNREADABLE, 'reason' => $reason, 'metrics' => []];
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function statusFromHeaders(array $headers): int
    {
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $mm) === 1) {
                $status = (int) $mm[1]; // last wins → final hop after redirects
            }
        }

        return $status;
    }
}
