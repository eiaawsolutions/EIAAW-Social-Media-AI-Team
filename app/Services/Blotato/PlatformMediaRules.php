<?php

namespace App\Services\Blotato;

/**
 * Source of truth for per-platform MEDIA-FILE constraints — the physical
 * properties of an image/video that decide whether the platform (via Blotato)
 * will accept the upload at publish time.
 *
 * This is the media-file complement to App\Services\Blotato\PlatformRules,
 * which governs the DRAFT (caption length, hashtag cap, media presence).
 * PlatformRules answers "does this draft have media?"; PlatformMediaRules
 * answers "is THIS media file physically publishable on this platform?".
 *
 * Why a separate manifest: the existing compliance gate only checks that a
 * media item is PRESENT, never that it's within size/dimension/duration
 * bounds. An operator can attach a 90 MB 4:5 phone video to a YouTube draft;
 * it passes the present-gate today and is rejected by YouTube at publish.
 * This manifest closes that gap so the upload modal can fail loudly (with
 * fixes) before the file ever reaches the schedule.
 *
 * Sources (verified Q2 2026):
 *   - Blotato OpenAPI v2 media constraints (backend.blotato.com/openapi.json)
 *   - Native platform publishing limits (Meta Graph, TikTok Content Posting
 *     API, YouTube Data API, Pinterest API, LinkedIn UGC, X media/upload)
 *
 * All limits are conservative: where a platform publishes a range we take the
 * tighter Blotato-enforced bound, so passing here ~= passing at publish.
 *
 * Aspect ratio is expressed as an inclusive [min, max] band of width/height.
 * A square 1.0 sits inside [0.8, 1.91] for feed images, etc. `null` for any
 * bound means "unconstrained on that axis".
 */
final class PlatformMediaRules
{
    private const MB = 1024 * 1024;

    /**
     * Per-platform, per-media-type manifest.
     *
     * image keys:
     *   max_bytes        : hard file-size cap
     *   max_width        : max pixel width  (null = unconstrained)
     *   max_height       : max pixel height (null = unconstrained)
     *   min_width        : min pixel width
     *   min_height       : min pixel height
     *   aspect_min       : min width/height ratio (inclusive)
     *   aspect_max       : max width/height ratio (inclusive)
     *   formats          : accepted lowercase extensions / encodings
     *
     * video keys (superset of image, minus aspect strictness on some):
     *   max_bytes, max_width, max_height, min_width, min_height,
     *   aspect_min, aspect_max, formats
     *   max_duration_s   : hard duration cap in seconds (null = unconstrained)
     *   min_duration_s   : min duration in seconds
     */
    public const RULES = [
        'instagram' => [
            'image' => [
                'max_bytes' => 8 * self::MB,
                'max_width' => 1440, 'max_height' => 1800,
                'min_width' => 320,  'min_height' => 320,
                'aspect_min' => 0.8,  // 4:5 portrait
                'aspect_max' => 1.91, // 1.91:1 landscape
                'formats' => ['jpg', 'jpeg', 'png'],
            ],
            'video' => [
                'max_bytes' => 100 * self::MB,
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 320,  'min_height' => 320,
                'aspect_min' => 0.5,  // up to 9:16 reels
                'aspect_max' => 1.78, // 16:9
                'max_duration_s' => 90, 'min_duration_s' => 3,
                'formats' => ['mp4', 'mov'],
            ],
        ],
        'facebook' => [
            'image' => [
                'max_bytes' => 30 * self::MB,
                'max_width' => 2048, 'max_height' => 2048,
                'min_width' => 200,  'min_height' => 200,
                'aspect_min' => 0.4, 'aspect_max' => 2.5,
                'formats' => ['jpg', 'jpeg', 'png', 'gif'],
            ],
            'video' => [
                'max_bytes' => 1024 * self::MB, // 1 GB
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 240,  'min_height' => 240,
                'aspect_min' => 0.5, 'aspect_max' => 1.78,
                'max_duration_s' => 1200, 'min_duration_s' => 1, // 20 min
                'formats' => ['mp4', 'mov'],
            ],
        ],
        'linkedin' => [
            'image' => [
                'max_bytes' => 10 * self::MB,
                'max_width' => 4000, 'max_height' => 4000,
                'min_width' => 200,  'min_height' => 200,
                'aspect_min' => 0.4, 'aspect_max' => 2.5,
                'formats' => ['jpg', 'jpeg', 'png', 'gif'],
            ],
            'video' => [
                'max_bytes' => 200 * self::MB,
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 256,  'min_height' => 144,
                'aspect_min' => 0.5, 'aspect_max' => 2.4,
                'max_duration_s' => 600, 'min_duration_s' => 3, // 10 min
                'formats' => ['mp4'],
            ],
        ],
        'tiktok' => [
            // TikTok is video-first; images go to photo-mode carousels.
            'image' => [
                'max_bytes' => 20 * self::MB,
                'max_width' => 1080, 'max_height' => 1920,
                'min_width' => 360,  'min_height' => 360,
                'aspect_min' => 0.5, 'aspect_max' => 1.0,
                'formats' => ['jpg', 'jpeg', 'png', 'webp'],
            ],
            'video' => [
                'max_bytes' => 287 * self::MB,
                'max_width' => 1080, 'max_height' => 1920,
                'min_width' => 360,  'min_height' => 360,
                'aspect_min' => 0.5,  // 9:16 ideal
                'aspect_max' => 1.0,  // up to square; landscape gets letterboxed/rejected
                'max_duration_s' => 600, 'min_duration_s' => 3,
                'formats' => ['mp4', 'mov', 'webm'],
            ],
        ],
        'youtube' => [
            // YouTube uploads here are Shorts/video; "image" = thumbnail-only,
            // but a draft asset on YouTube must be a video. We still define an
            // image rule so a mistakenly-attached image fails with a clear
            // "YouTube needs a video" message rather than a vague size error.
            'image' => [
                'max_bytes' => 2 * self::MB,
                'max_width' => 1280, 'max_height' => 720,
                'min_width' => 640,  'min_height' => 360,
                'aspect_min' => 1.7, 'aspect_max' => 1.79, // 16:9 thumbnail
                'formats' => ['jpg', 'jpeg', 'png'],
            ],
            'video' => [
                'max_bytes' => 256 * self::MB, // Blotato-side cap for API uploads
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 426,  'min_height' => 240,
                'aspect_min' => 0.5, 'aspect_max' => 1.78,
                'max_duration_s' => 180, 'min_duration_s' => 1, // Shorts/standard via API
                'formats' => ['mp4', 'mov'],
            ],
        ],
        'pinterest' => [
            'image' => [
                'max_bytes' => 20 * self::MB,
                'max_width' => 2000, 'max_height' => 3000,
                'min_width' => 300,  'min_height' => 300,
                'aspect_min' => 0.5,  // tall pins preferred
                'aspect_max' => 1.0,
                'formats' => ['jpg', 'jpeg', 'png'],
            ],
            'video' => [
                'max_bytes' => 200 * self::MB,
                'max_width' => 1080, 'max_height' => 1920,
                'min_width' => 240,  'min_height' => 240,
                'aspect_min' => 0.5, 'aspect_max' => 1.0,
                'max_duration_s' => 300, 'min_duration_s' => 4,
                'formats' => ['mp4', 'mov'],
            ],
        ],
        'threads' => [
            'image' => [
                'max_bytes' => 8 * self::MB,
                'max_width' => 1440, 'max_height' => 1800,
                'min_width' => 320,  'min_height' => 320,
                'aspect_min' => 0.5, 'aspect_max' => 1.91,
                'formats' => ['jpg', 'jpeg', 'png'],
            ],
            'video' => [
                'max_bytes' => 100 * self::MB,
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 320,  'min_height' => 320,
                'aspect_min' => 0.5, 'aspect_max' => 1.78,
                'max_duration_s' => 300, 'min_duration_s' => 1,
                'formats' => ['mp4', 'mov'],
            ],
        ],
        'x' => [
            'image' => [
                'max_bytes' => 5 * self::MB,
                'max_width' => 4096, 'max_height' => 4096,
                'min_width' => 200,  'min_height' => 200,
                'aspect_min' => 0.33, 'aspect_max' => 3.0,
                'formats' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            ],
            'video' => [
                'max_bytes' => 512 * self::MB,
                'max_width' => 1920, 'max_height' => 1920,
                'min_width' => 32,   'min_height' => 32,
                'aspect_min' => 0.5, 'aspect_max' => 2.0,
                'max_duration_s' => 140, 'min_duration_s' => 1,
                'formats' => ['mp4', 'mov'],
            ],
        ],
    ];

    /** Conservative default for any platform not explicitly listed. */
    public const DEFAULT_RULE = [
        'image' => [
            'max_bytes' => 8 * self::MB,
            'max_width' => 2048, 'max_height' => 2048,
            'min_width' => 200,  'min_height' => 200,
            'aspect_min' => 0.4, 'aspect_max' => 2.5,
            'formats' => ['jpg', 'jpeg', 'png'],
        ],
        'video' => [
            'max_bytes' => 100 * self::MB,
            'max_width' => 1920, 'max_height' => 1920,
            'min_width' => 240,  'min_height' => 240,
            'aspect_min' => 0.5, 'aspect_max' => 1.78,
            'max_duration_s' => 180, 'min_duration_s' => 1,
            'formats' => ['mp4', 'mov'],
        ],
    ];

    /**
     * @return array<string,mixed> the media rule block for $platform/$mediaType
     */
    public static function for(string $platform, string $mediaType): array
    {
        $platform = strtolower($platform);
        $mediaType = $mediaType === 'video' ? 'video' : 'image';
        $block = self::RULES[$platform] ?? self::DEFAULT_RULE;
        return $block[$mediaType] ?? self::DEFAULT_RULE[$mediaType];
    }

    /** Human "≤ N MB" helper for operator-facing messages. */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= self::MB) {
            return rtrim(rtrim(number_format($bytes / self::MB, 1), '0'), '.') . ' MB';
        }
        return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    }

    /** Human aspect band, e.g. "between 9:16 and 16:9" → "0.50–1.78 (w/h)". */
    public static function humanAspect(?float $min, ?float $max): string
    {
        if ($min === null && $max === null) return 'any aspect ratio';
        return sprintf('%s–%s (width ÷ height)', number_format((float) $min, 2), number_format((float) $max, 2));
    }
}
