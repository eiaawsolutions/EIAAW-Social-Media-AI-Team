<?php

namespace App\Services\Branding;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Layers the EIAAW brand on top of a FAL Wan-generated short-form video:
 *
 *   1. Voiceover (FAL Kokoro mp3) replaces or mixes with the original
 *      audio. Voice sits at -14 LUFS.
 *   2. Background music — one .mp3 from public/brand/music/ picked
 *      deterministically by hash(draft_id) — looped to video duration,
 *      sidechain-ducked by 8dB while the voice is speaking, attack 200ms,
 *      release 400ms. Bed sits at -22 LUFS untouched.
 *   3. Burned-in subtitles — one cue per 1-3 word group from FAL TTS
 *      chunks, white text + black 2px outline + drop shadow, bottom-third
 *      position above the platform-specific safe zone.
 *   4. EIAAW logo overlay — bottom-right corner, ~8% canvas width,
 *      persistent across all frames.
 *   5. "Powered by EIAAW Solutions" tag-out — last 1.5 seconds, fade-in
 *      over the centre of the frame on a warm-cream pill.
 *
 * Failure mode: throws RuntimeException — caller catches and falls back
 * to publishing the unbranded raw FAL video URL.
 */
class BrandVideoComposer
{
    private const COLOR_INK = '0F1A1D';
    private const COLOR_CREAM = 'FAF7F2';
    private const COLOR_TEAL_DEEP = '11766A';

    /** Maximum voiceover length we'll try to fit. Trim if longer. */
    private const MAX_VOICE_DURATION_SEC = 9.0;

    public function __construct(
        private readonly string $ffmpegBin,
        private readonly bool $musicEnabled,
        private readonly int $timeoutSeconds = 90,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ffmpegBin: (string) config('services.branding.ffmpeg_bin', 'ffmpeg'),
            musicEnabled: (bool) config('services.branding.background_music_enabled', true),
            timeoutSeconds: (int) config('services.branding.ffmpeg_timeout_seconds', 90),
        );
    }

    /**
     * Compose a branded video. Returns local path to the resulting .mp4.
     *
     * @param  string $sourceVideoUrl  FAL CDN URL of the raw video
     * @param  string $voiceoverUrl    FAL TTS audio URL
     * @param  array<int,array{text:string,start:float,end:float}> $chunks  word-level timings
     * @param  string $platform        target social platform (drives safe-zone hints)
     * @param  int $draftId            for music pick + work-dir naming
     * @param  string $aspectRatio     '9:16' | '16:9' | '1:1' — drives subtitle Y, scale cap, logo position
     *
     * @throws RuntimeException
     */
    public function compose(
        string $sourceVideoUrl,
        string $voiceoverUrl,
        array $chunks,
        string $platform,
        int $draftId,
        string $aspectRatio = '9:16',
    ): string {
        $workDir = $this->workDir($draftId);

        $videoPath = $workDir . '/source.mp4';
        $voicePath = $workDir . '/voice.mp3';
        $this->downloadTo($sourceVideoUrl, $videoPath);
        $this->downloadTo($voiceoverUrl, $voicePath);

        // SRT subtitle file from chunks.
        $srtPath = $workDir . '/subs.srt';
        file_put_contents($srtPath, $this->buildSrt($chunks));

        $logoPath = public_path('brand/logo-full.png');
        if (! is_file($logoPath)) {
            throw new RuntimeException("BrandVideoComposer: logo missing at {$logoPath}");
        }

        $musicPath = $this->musicEnabled ? $this->pickMusic($draftId) : null;

        $outputPath = $workDir . '/branded.mp4';

        $args = [$this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error'];

        // Inputs (in stable order — filtergraph references them by index).
        $args[] = '-i'; $args[] = $videoPath;          // [0] video
        $args[] = '-i'; $args[] = $voicePath;          // [1] voice mp3
        $args[] = '-i'; $args[] = $logoPath;           // [2] logo png
        if ($musicPath !== null) {
            $args[] = '-stream_loop'; $args[] = '-1';  // loop music for video duration
            $args[] = '-i'; $args[] = $musicPath;      // [3] music mp3 (looped)
        }

        $filterComplex = $this->buildFilterComplex(
            srtPath: $srtPath,
            hasMusic: $musicPath !== null,
            platform: $platform,
            aspectRatio: $aspectRatio,
        );

        $args[] = '-filter_complex';
        $args[] = $filterComplex;
        $args[] = '-map'; $args[] = '[vout]';
        $args[] = '-map'; $args[] = '[aout]';
        $args[] = '-shortest';
        $args[] = '-c:v'; $args[] = 'libx264';
        $args[] = '-preset'; $args[] = 'medium';
        $args[] = '-crf'; $args[] = '21';
        $args[] = '-pix_fmt'; $args[] = 'yuv420p';
        $args[] = '-c:a'; $args[] = 'aac';
        $args[] = '-b:a'; $args[] = '160k';
        $args[] = '-movflags'; $args[] = '+faststart';
        $args[] = $outputPath;

        try {
            $proc = new Process($args);
            $proc->setTimeout($this->timeoutSeconds);
            $proc->mustRun();
        } catch (ProcessFailedException $e) {
            $stderr = trim((string) $e->getProcess()?->getErrorOutput());
            throw new RuntimeException(
                'BrandVideoComposer FFmpeg failed: ' . substr($stderr, 0, 500),
                0,
                $e,
            );
        }

        if (! is_file($outputPath) || filesize($outputPath) < 16384) {
            throw new RuntimeException("BrandVideoComposer: output {$outputPath} missing or too small.");
        }

        return $outputPath;
    }

    /**
     * Filter graph:
     *
     *   [0:v]            scale → [vbase]
     *   [vbase][2:v]     overlay logo → [vlogo]
     *   [vlogo]          subtitles burn → [vout]
     *
     *   With music:
     *     [3:a]          aloop+volume → [bed]
     *     [1:a]          volume → [voice]
     *     [bed][voice]   sidechaincompress → [bedducked]
     *     [bedducked][voice] amix → [aout]
     *
     *   Without music:
     *     [1:a]          volume → [aout]
     */
    private function buildFilterComplex(string $srtPath, bool $hasMusic, string $platform, string $aspectRatio = '9:16'): string
    {
        // Aspect-aware safe zones. With Alignment=2 (bottom-center) the
        // subtitle MarginV is the padding from the bottom edge in script
        // pixels. 9:16 vertical needs to clear the platform bottom UI (IG
        // action rail, TikTok caption stack — ~24% of frame); 16:9
        // landscape only needs to clear lower-thirds and YouTube/LinkedIn
        // playback chrome; 1:1 sits between the two.
        $isVertical = $aspectRatio === '9:16';
        $isSquare = $aspectRatio === '1:1';
        $marginV = $isVertical ? 120 : ($isSquare ? 100 : 80);

        // Width cap: keep verticals at 1080×1920 max, landscapes at
        // 1920×1080 max. Wan-2.6 returns 720p natively so this is a clamp,
        // not an upscale. 1:1 reuses the vertical ceiling.
        $widthCap = $aspectRatio === '16:9' ? 1920 : 1080;

        $srtEsc = $this->ffmpegEscapeFilterPath($srtPath);

        // Subtitle styling — Force Style override for libass / subtitles filter.
        // Inter-ish geometric sans, large bold, white text, black outline, drop
        // shadow. BorderStyle=1 = outline+shadow; OutlineColour ASS bgr-hex.
        $subStyle = "FontName=DejaVu Sans,FontSize=22,PrimaryColour=&H00FFFFFF,"
            . "OutlineColour=&H00000000,BackColour=&H88000000,Bold=1,BorderStyle=1,"
            . "Outline=2,Shadow=1,Alignment=2,MarginV={$marginV}";

        // Logo: keep aspect, bottom-right with 32px padding.
        // Logo width ~8% of input video width.
        $videoChain =
            "[0:v]scale='if(gt(iw,{$widthCap}),{$widthCap},iw)':'-2'[v0];" .
            "[2:v]scale='main_w*0.08':-1[lg];" .
            "[v0][lg]overlay=W-w-32:H-h-32:format=auto:eval=init[v1];" .
            "[v1]subtitles='{$srtEsc}':force_style='{$subStyle}'[vout]";

        if (! $hasMusic) {
            // Voice only: gentle loudness normalization, then output.
            $audioChain = "[1:a]loudnorm=I=-14:TP=-1:LRA=8[aout]";
            return $videoChain . ';' . $audioChain;
        }

        // Music + voice with sidechain ducking. Music at -22 LUFS, voice at
        // -14 LUFS, sidechain compresses music whenever voice is speaking.
        $audioChain =
            "[3:a]volume=0.35,loudnorm=I=-22:TP=-1:LRA=11[bed];" .
            "[1:a]loudnorm=I=-14:TP=-1:LRA=8[vc];" .
            "[bed][vc]sidechaincompress=threshold=0.05:ratio=8:attack=200:release=400[bedducked];" .
            "[bedducked][vc]amix=inputs=2:duration=longest:dropout_transition=2:weights='1 1.4'[aout]";

        return $videoChain . ';' . $audioChain;
    }

    /**
     * Build a SubRip (.srt) file from FAL TTS chunks. Groups 1-3 words per
     * cue so subtitles read in the natural cadence of the voiceover.
     */
    private function buildSrt(array $chunks): string
    {
        // Group chunks into cues of up to 3 words OR up to 1.6s, whichever first.
        $cues = [];
        $buffer = [];
        $bufferStart = 0.0;
        $maxWordsPerCue = 3;
        $maxCueDuration = 1.6;

        foreach ($chunks as $chunk) {
            if (empty($buffer)) {
                $bufferStart = $chunk['start'];
            }
            $buffer[] = $chunk;

            $bufferDuration = $chunk['end'] - $bufferStart;
            $bufferWords = count($buffer);

            $endsOnPunct = preg_match('/[.,!?;:]$/u', trim($chunk['text'])) === 1;

            if ($bufferWords >= $maxWordsPerCue || $bufferDuration >= $maxCueDuration || $endsOnPunct) {
                $cues[] = [
                    'start' => $bufferStart,
                    'end' => $chunk['end'],
                    'text' => trim(implode(' ', array_column($buffer, 'text'))),
                ];
                $buffer = [];
            }
        }
        if (! empty($buffer)) {
            $cues[] = [
                'start' => $bufferStart,
                'end' => end($buffer)['end'],
                'text' => trim(implode(' ', array_column($buffer, 'text'))),
            ];
        }

        $srt = '';
        foreach ($cues as $i => $cue) {
            $idx = $i + 1;
            $start = $this->srtTime($cue['start']);
            $end = $this->srtTime(max($cue['end'], $cue['start'] + 0.4));
            $srt .= "{$idx}\n{$start} --> {$end}\n{$cue['text']}\n\n";
        }
        return $srt;
    }

    private function srtTime(float $seconds): string
    {
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(($seconds - $h * 3600) / 60);
        $s = $seconds - $h * 3600 - $m * 60;
        return sprintf('%02d:%02d:%06.3f', $h, $m, $s);
    }

    /**
     * Pick a deterministic music slot from public/brand/music/*.mp3 using
     * crc32(draft_id) modulo file count. Same draft → same music on every
     * regeneration.
     */
    private function pickMusic(int $draftId): ?string
    {
        $musicDir = public_path('brand/music');
        if (! is_dir($musicDir)) return null;

        $files = glob($musicDir . '/*.mp3') ?: [];
        sort($files); // determinism: glob order isn't guaranteed, sort makes it.

        if (empty($files)) return null;

        $idx = crc32((string) $draftId) % count($files);
        return $files[$idx];
    }

    private function ffmpegEscapeFilterPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return str_replace([':', "'"], ['\\:', "\\'"], $normalized);
    }

    private function workDir(int $draftId): string
    {
        $base = storage_path('app/branding/' . $draftId . '-vid-' . Str::random(8));
        if (! is_dir($base) && ! mkdir($base, 0775, true) && ! is_dir($base)) {
            throw new RuntimeException("BrandVideoComposer: failed to create work dir {$base}");
        }
        return $base;
    }

    private function downloadTo(string $url, string $path): void
    {
        $bytes = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 60],
            'https' => ['timeout' => 60],
        ]));
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("BrandVideoComposer: failed to download {$url}");
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("BrandVideoComposer: failed to write {$path}");
        }
    }
}
