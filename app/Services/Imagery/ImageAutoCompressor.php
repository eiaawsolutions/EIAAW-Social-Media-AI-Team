<?php

namespace App\Services\Imagery;

use App\Services\Blotato\PlatformMediaRules;
use RuntimeException;

/**
 * Brings an over-spec IMAGE inside a platform's media envelope without
 * operator action: downscale to the max dimensions (preserving aspect, never
 * upscaling) then re-encode as progressively-lower-quality JPEG until the file
 * is under the byte cap.
 *
 * Scope, deliberately narrow:
 *   - Fixes ONLY oversize_bytes, oversize_dimensions, and image-format
 *     mismatch (any decodable image → JPEG). Output is always a baseline JPEG.
 *   - Does NOT fix aspect_out_of_band or too_small — cropping changes the
 *     operator's chosen composition and upscaling degrades quality, so those
 *     stay as fail-with-suggestion. MediaComplianceChecker marks each
 *     violation `fixable_by_compression` so the caller knows whether to try.
 *
 * Pure GD (ext-gd is a hard dependency of this app). No external process.
 *
 * Returns the path of a NEW file (never mutates the input) so the caller can
 * decide whether to replace the original. Caller is responsible for cleanup.
 */
class ImageAutoCompressor
{
    /** JPEG quality ladder — first that fits under the cap wins. */
    private const QUALITY_STEPS = [90, 82, 74, 66, 58, 50, 42];

    /**
     * Compress/resize $sourcePath to satisfy the platform's image rule.
     *
     * @return array{path:string, width:int, height:int, bytes:int, quality:int}
     *
     * @throws RuntimeException if the image can't be decoded or no quality
     *         step gets it under the cap (caller then surfaces a fail popup).
     */
    public function compressForPlatform(string $sourcePath, string $platform): array
    {
        $rule = PlatformMediaRules::for($platform, 'image');
        return $this->compress(
            $sourcePath,
            maxWidth: $rule['max_width'] ?? null,
            maxHeight: $rule['max_height'] ?? null,
            maxBytes: (int) ($rule['max_bytes'] ?? 0),
        );
    }

    /**
     * @return array{path:string, width:int, height:int, bytes:int, quality:int}
     *
     * @throws RuntimeException
     */
    public function compress(string $sourcePath, ?int $maxWidth, ?int $maxHeight, int $maxBytes): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("ImageAutoCompressor: source not found at {$sourcePath}");
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('ImageAutoCompressor: source is not a decodable image.');
        }
        [$srcW, $srcH] = $info;
        $srcW = (int) $srcW;
        $srcH = (int) $srcH;

        $src = $this->loadImage($sourcePath, (int) ($info[2] ?? 0));
        if ($src === null) {
            throw new RuntimeException('ImageAutoCompressor: unsupported image type for re-encoding.');
        }

        // Compute target dimensions: scale down to fit within max box,
        // preserving aspect ratio, never enlarging.
        [$dstW, $dstH] = $this->fitWithin($srcW, $srcH, $maxWidth, $maxHeight);

        $canvas = imagecreatetruecolor($dstW, $dstH);
        // Flatten any alpha onto white (JPEG has no alpha) so PNGs/WebPs with
        // transparency don't come out with black backgrounds.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        $outPath = $this->tempPath();

        $finalQuality = 0;
        $fits = false;
        foreach (self::QUALITY_STEPS as $quality) {
            if (! imagejpeg($canvas, $outPath, $quality)) {
                continue;
            }
            $finalQuality = $quality;
            clearstatcache(true, $outPath);
            $bytes = (int) (filesize($outPath) ?: PHP_INT_MAX);
            if ($maxBytes <= 0 || $bytes <= $maxBytes) {
                $fits = true;
                break;
            }
        }

        // Still too big at lowest quality? Step the dimensions down 15% and
        // retry the quality ladder once. One extra pass is enough for realistic
        // phone-camera files; beyond that we give up and let the caller fail.
        if (! $fits && $maxBytes > 0) {
            $reW = (int) max(1, round($dstW * 0.85));
            $reH = (int) max(1, round($dstH * 0.85));
            $shrunk = imagecreatetruecolor($reW, $reH);
            $white2 = imagecolorallocate($shrunk, 255, 255, 255);
            imagefilledrectangle($shrunk, 0, 0, $reW, $reH, $white2);
            imagecopyresampled($shrunk, $canvas, 0, 0, 0, 0, $reW, $reH, $dstW, $dstH);
            imagedestroy($canvas);
            $canvas = $shrunk;
            $dstW = $reW;
            $dstH = $reH;

            foreach (self::QUALITY_STEPS as $quality) {
                if (! imagejpeg($canvas, $outPath, $quality)) {
                    continue;
                }
                $finalQuality = $quality;
                clearstatcache(true, $outPath);
                $bytes = (int) (filesize($outPath) ?: PHP_INT_MAX);
                if ($bytes <= $maxBytes) {
                    $fits = true;
                    break;
                }
            }
        }

        imagedestroy($canvas);

        clearstatcache(true, $outPath);
        $finalBytes = (int) (filesize($outPath) ?: 0);

        if (! $fits && $maxBytes > 0 && $finalBytes > $maxBytes) {
            @unlink($outPath);
            throw new RuntimeException(sprintf(
                'Could not compress under %s (smallest we reached was %s). The source may be extremely detailed — try a simpler image.',
                PlatformMediaRules::humanBytes($maxBytes),
                PlatformMediaRules::humanBytes($finalBytes),
            ));
        }

        return [
            'path' => $outPath,
            'width' => $dstW,
            'height' => $dstH,
            'bytes' => $finalBytes,
            'quality' => $finalQuality,
        ];
    }

    /**
     * @return array{0:int,1:int} target [width, height] fitting within the box
     */
    private function fitWithin(int $w, int $h, ?int $maxW, ?int $maxH): array
    {
        $scale = 1.0;
        if ($maxW !== null && $maxW > 0 && $w > $maxW) {
            $scale = min($scale, $maxW / $w);
        }
        if ($maxH !== null && $maxH > 0 && $h > $maxH) {
            $scale = min($scale, $maxH / $h);
        }
        if ($scale >= 1.0) {
            return [$w, $h]; // already inside the box — only re-encode for bytes
        }
        return [max(1, (int) floor($w * $scale)), max(1, (int) floor($h * $scale))];
    }

    private function loadImage(string $path, int $type): ?\GdImage
    {
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }

    private function tempPath(): string
    {
        $dir = storage_path('app/media-compress');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("ImageAutoCompressor: failed to create work dir {$dir}");
        }
        return $dir . '/' . bin2hex(random_bytes(8)) . '.jpg';
    }
}
