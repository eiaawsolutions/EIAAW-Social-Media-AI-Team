<?php

namespace App\Services\Imagery;

use App\Services\Blotato\PlatformMediaRules;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Validates a LOCAL media file against the publishability limits in
 * PlatformMediaRules and returns structured, operator-facing violations.
 *
 * This is the media-file half of the compliance gate. The draft-level gate
 * (App\Agents\ComplianceAgent → PlatformRules) checks caption/hashtag/media-
 * presence; this checks that the attached file is physically within the
 * platform's size / dimension / aspect / duration / format envelope.
 *
 * Each violation carries:
 *   - kind        : machine handle (oversize_bytes, oversize_dimensions,
 *                   aspect_out_of_band, duration_too_long, unsupported_format,
 *                   too_small, probe_failed)
 *   - reason      : what is wrong, in plain English with the actual vs allowed
 *   - suggestion  : the concrete thing the operator should do to fix it
 *   - fixable_by_compression : true only for image byte/dimension issues that
 *                   ImageAutoCompressor can resolve without operator action
 *
 * Images are probed with getimagesize() (ext-gd, always available here).
 * Videos are probed with ffprobe (config services.branding.ffprobe_bin). If
 * ffprobe is unavailable we DEGRADE: we still enforce byte-size + format from
 * the filename, but skip dimension/duration/aspect (recorded as a single
 * advisory note) rather than blocking — better to let a slightly-wrong video
 * through to the publish-time gate than to hard-block when we can't measure.
 */
class MediaComplianceChecker
{
    /**
     * @return array{
     *   passed: bool,
     *   media_type: string,
     *   violations: array<int, array{kind:string, reason:string, suggestion:string, fixable_by_compression:bool, detail:array}>,
     *   probe: array<string,mixed>
     * }
     */
    public function check(string $localPath, string $platform, string $mediaType): array
    {
        $mediaType = $mediaType === 'video' ? 'video' : 'image';
        $rule = PlatformMediaRules::for($platform, $mediaType);
        $violations = [];
        $probe = [];

        if (! is_file($localPath)) {
            return [
                'passed' => false,
                'media_type' => $mediaType,
                'violations' => [[
                    'kind' => 'probe_failed',
                    'reason' => 'The uploaded file could not be read on the server.',
                    'suggestion' => 'Re-upload the file. If it keeps failing, the file may be corrupt — try re-exporting it.',
                    'fixable_by_compression' => false,
                    'detail' => ['path' => $localPath],
                ]],
                'probe' => [],
            ];
        }

        $bytes = (int) (filesize($localPath) ?: 0);
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $probe['bytes'] = $bytes;
        $probe['ext'] = $ext;

        // ── Format ──
        if (! empty($rule['formats']) && $ext !== '' && ! in_array($ext, $rule['formats'], true)) {
            $violations[] = [
                'kind' => 'unsupported_format',
                'reason' => sprintf(
                    '%s does not accept .%s files for %ss. Accepted: %s.',
                    ucfirst($platform), $ext, $mediaType, implode(', ', $rule['formats']),
                ),
                'suggestion' => $mediaType === 'image'
                    ? 'Re-export as JPG or PNG.'
                    : 'Re-export as an MP4 (H.264/AAC).',
                // image format mismatch IS fixable: the compressor always
                // outputs JPEG; video format mismatch is not.
                'fixable_by_compression' => $mediaType === 'image',
                'detail' => ['ext' => $ext, 'accepted' => $rule['formats']],
            ];
        }

        // ── Byte size ──
        if (isset($rule['max_bytes']) && $bytes > (int) $rule['max_bytes']) {
            $violations[] = [
                'kind' => 'oversize_bytes',
                'reason' => sprintf(
                    'File is %s; %s allows up to %s for %ss.',
                    PlatformMediaRules::humanBytes($bytes),
                    ucfirst($platform),
                    PlatformMediaRules::humanBytes((int) $rule['max_bytes']),
                    $mediaType,
                ),
                'suggestion' => $mediaType === 'image'
                    ? 'No action needed — we can compress this for you.'
                    : sprintf('Re-export at a lower bitrate/resolution so the file is under %s.', PlatformMediaRules::humanBytes((int) $rule['max_bytes'])),
                'fixable_by_compression' => $mediaType === 'image',
                'detail' => ['bytes' => $bytes, 'max_bytes' => (int) $rule['max_bytes']],
            ];
        }

        if ($mediaType === 'image') {
            $this->checkImage($localPath, $rule, $platform, $violations, $probe);
        } else {
            $this->checkVideo($localPath, $rule, $platform, $violations, $probe);
        }

        return [
            'passed' => empty($violations),
            'media_type' => $mediaType,
            'violations' => $violations,
            'probe' => $probe,
        ];
    }

    /**
     * Image dimension + aspect checks via getimagesize.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<int,array>  $violations  (by-ref)
     * @param  array<string,mixed>  $probe     (by-ref)
     */
    private function checkImage(string $path, array $rule, string $platform, array &$violations, array &$probe): void
    {
        $size = @getimagesize($path);
        if ($size === false) {
            $violations[] = [
                'kind' => 'probe_failed',
                'reason' => 'The file is not a readable image.',
                'suggestion' => 'Re-export as a standard JPG or PNG and upload again.',
                'fixable_by_compression' => false,
                'detail' => [],
            ];
            return;
        }
        [$w, $h] = $size;
        $w = (int) $w;
        $h = (int) $h;
        $probe['width'] = $w;
        $probe['height'] = $h;

        $this->checkDimensionsAndAspect($w, $h, $rule, $platform, 'image', $violations, fixableOversize: true);
    }

    /**
     * Video dimension/aspect/duration checks via ffprobe. Degrades gracefully
     * if ffprobe is unavailable.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<int,array>  $violations  (by-ref)
     * @param  array<string,mixed>  $probe     (by-ref)
     */
    private function checkVideo(string $path, array $rule, string $platform, array &$violations, array &$probe): void
    {
        $meta = $this->ffprobe($path);
        if ($meta === null) {
            // Couldn't measure — keep byte/format checks already done, note it.
            $probe['ffprobe'] = 'unavailable';
            $violations[] = [
                'kind' => 'probe_advisory',
                'reason' => 'Could not measure this video\'s dimensions/duration on the server (ffprobe unavailable). Size and format were still checked.',
                'suggestion' => sprintf(
                    'Make sure the clip is %s, under %s, and within %ss before publishing.',
                    PlatformMediaRules::humanAspect($rule['aspect_min'] ?? null, $rule['aspect_max'] ?? null),
                    PlatformMediaRules::humanBytes((int) ($rule['max_bytes'] ?? 0)),
                    $rule['max_duration_s'] ?? '∞',
                ),
                'fixable_by_compression' => false,
                'detail' => [],
            ];
            return;
        }

        $w = (int) ($meta['width'] ?? 0);
        $h = (int) ($meta['height'] ?? 0);
        $duration = (float) ($meta['duration'] ?? 0);
        $probe['width'] = $w;
        $probe['height'] = $h;
        $probe['duration_s'] = round($duration, 2);

        if ($w > 0 && $h > 0) {
            // Video oversize/aspect are NOT compression-fixable here.
            $this->checkDimensionsAndAspect($w, $h, $rule, $platform, 'video', $violations, fixableOversize: false);
        }

        // ── Duration ──
        if (isset($rule['max_duration_s']) && $rule['max_duration_s'] !== null && $duration > (float) $rule['max_duration_s']) {
            $violations[] = [
                'kind' => 'duration_too_long',
                'reason' => sprintf(
                    'Video is %.1fs; %s allows up to %ds.',
                    $duration, ucfirst($platform), (int) $rule['max_duration_s'],
                ),
                'suggestion' => sprintf('Trim the clip to %ds or less, then re-upload.', (int) $rule['max_duration_s']),
                'fixable_by_compression' => false,
                'detail' => ['duration_s' => round($duration, 2), 'max_duration_s' => (int) $rule['max_duration_s']],
            ];
        }
        if (isset($rule['min_duration_s']) && $duration > 0 && $duration < (float) $rule['min_duration_s']) {
            $violations[] = [
                'kind' => 'duration_too_short',
                'reason' => sprintf(
                    'Video is only %.1fs; %s needs at least %ds.',
                    $duration, ucfirst($platform), (int) $rule['min_duration_s'],
                ),
                'suggestion' => sprintf('Use a clip of at least %ds.', (int) $rule['min_duration_s']),
                'fixable_by_compression' => false,
                'detail' => ['duration_s' => round($duration, 2), 'min_duration_s' => (int) $rule['min_duration_s']],
            ];
        }
    }

    /**
     * Shared dimension + aspect-band evaluation for both media types.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<int,array>  $violations  (by-ref)
     */
    private function checkDimensionsAndAspect(
        int $w,
        int $h,
        array $rule,
        string $platform,
        string $mediaType,
        array &$violations,
        bool $fixableOversize,
    ): void {
        // Too small (never compression-fixable — upscaling degrades quality).
        $minW = (int) ($rule['min_width'] ?? 0);
        $minH = (int) ($rule['min_height'] ?? 0);
        if (($minW > 0 && $w < $minW) || ($minH > 0 && $h < $minH)) {
            $violations[] = [
                'kind' => 'too_small',
                'reason' => sprintf(
                    'Resolution is %d×%d; %s needs at least %d×%d for %ss.',
                    $w, $h, ucfirst($platform), $minW, $minH, $mediaType,
                ),
                'suggestion' => 'Use a higher-resolution source — upscaling a small file would look blurry.',
                'fixable_by_compression' => false,
                'detail' => ['width' => $w, 'height' => $h, 'min_width' => $minW, 'min_height' => $minH],
            ];
        }

        // Too large in pixels.
        $maxW = $rule['max_width'] ?? null;
        $maxH = $rule['max_height'] ?? null;
        if (($maxW !== null && $w > (int) $maxW) || ($maxH !== null && $h > (int) $maxH)) {
            $violations[] = [
                'kind' => 'oversize_dimensions',
                'reason' => sprintf(
                    'Resolution is %d×%d; %s caps %ss at %s×%s.',
                    $w, $h, ucfirst($platform), $mediaType,
                    $maxW !== null ? (int) $maxW : '∞',
                    $maxH !== null ? (int) $maxH : '∞',
                ),
                'suggestion' => $fixableOversize
                    ? 'No action needed — we can resize this for you.'
                    : sprintf('Re-export the video at %s×%s or smaller.', $maxW !== null ? (int) $maxW : '∞', $maxH !== null ? (int) $maxH : '∞'),
                'fixable_by_compression' => $fixableOversize,
                'detail' => ['width' => $w, 'height' => $h, 'max_width' => $maxW, 'max_height' => $maxH],
            ];
        }

        // Aspect band. Aspect is NOT auto-fixable (cropping changes the
        // composition the operator chose — we surface it for them to decide).
        $aspectMin = $rule['aspect_min'] ?? null;
        $aspectMax = $rule['aspect_max'] ?? null;
        if ($h > 0 && ($aspectMin !== null || $aspectMax !== null)) {
            $aspect = $w / $h;
            $belowMin = $aspectMin !== null && $aspect < (float) $aspectMin - 0.01;
            $aboveMax = $aspectMax !== null && $aspect > (float) $aspectMax + 0.01;
            if ($belowMin || $aboveMax) {
                $violations[] = [
                    'kind' => 'aspect_out_of_band',
                    'reason' => sprintf(
                        '%s is %.2f wide÷tall; %s accepts %s for %ss.',
                        $belowMin ? 'Too tall/narrow' : 'Too wide/short',
                        $aspect,
                        ucfirst($platform),
                        PlatformMediaRules::humanAspect($aspectMin, $aspectMax),
                        $mediaType,
                    ),
                    'suggestion' => sprintf(
                        'Crop or re-frame to %s. For %s, %s usually works best.',
                        PlatformMediaRules::humanAspect($aspectMin, $aspectMax),
                        ucfirst($platform),
                        $this->idealAspectHint($platform, $mediaType),
                    ),
                    'fixable_by_compression' => false,
                    'detail' => ['aspect' => round($aspect, 3), 'aspect_min' => $aspectMin, 'aspect_max' => $aspectMax],
                ];
            }
        }
    }

    private function idealAspectHint(string $platform, string $mediaType): string
    {
        return match (strtolower($platform)) {
            'tiktok' => '9:16 vertical',
            'youtube' => $mediaType === 'video' ? '16:9 or 9:16' : '16:9',
            'pinterest' => '2:3 vertical',
            'instagram', 'threads' => $mediaType === 'video' ? '9:16 or 4:5' : '4:5 or 1:1',
            default => '1:1 square or 4:5 portrait',
        };
    }

    /**
     * Probe a local video via ffprobe. Returns null when ffprobe is missing
     * or the probe fails — caller degrades gracefully.
     *
     * @return array{width:int,height:int,duration:float}|null
     */
    private function ffprobe(string $path): ?array
    {
        $bin = (string) config('services.branding.ffprobe_bin', 'ffprobe');
        $timeout = (int) config('services.branding.ffmpeg_timeout_seconds', 90);

        $args = [
            $bin, '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:format=duration',
            '-of', 'json',
            $path,
        ];

        try {
            $proc = new Process($args);
            $proc->setTimeout(min($timeout, 30));
            $proc->mustRun();
        } catch (ProcessFailedException $e) {
            Log::warning('MediaComplianceChecker: ffprobe failed', [
                'path' => $path,
                'stderr' => substr((string) $e->getProcess()?->getErrorOutput(), 0, 300),
            ]);
            return null;
        } catch (\Throwable $e) {
            // Binary not found / not executable.
            Log::info('MediaComplianceChecker: ffprobe unavailable', ['error' => substr($e->getMessage(), 0, 200)]);
            return null;
        }

        $json = json_decode($proc->getOutput(), true);
        if (! is_array($json)) return null;

        $stream = $json['streams'][0] ?? [];
        return [
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'duration' => (float) ($json['format']['duration'] ?? 0),
        ];
    }
}
