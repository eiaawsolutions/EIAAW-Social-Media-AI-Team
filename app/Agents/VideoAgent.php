<?php

namespace App\Agents;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Draft;
use App\Services\Blotato\BlotatoClient;
use App\Services\Branding\BrandVideoComposer;
use App\Services\Branding\FalTtsClient;
use App\Services\Branding\QuoteWriter;
use App\Services\Imagery\BrandAssetPicker;
use App\Services\Imagery\EiaawBrandLock;
use App\Services\Imagery\FalAiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Generates one short-form vertical video per Draft via FAL.AI Wan 2.6,
 * then re-hosts it on Blotato so it can attach to /v2/posts. Reels /
 * Shorts / TikTok are the highest-CPM organic distribution in 2026 —
 * skipping vertical video = leaving 60%+ of platform performance on
 * the table.
 *
 * Routing:
 *   - If draft.asset_url is a still image → image-to-video (Wan 2.6 i2v)
 *     uses the still as keyframe — better brand consistency, faster
 *     prompt convergence.
 *   - Else → text-to-video (Wan 2.6 t2v) directly from caption + visual_direction.
 *
 * Output: replaces draft.asset_url with the Blotato-hosted .mp4 URL,
 * keeps the original still in asset_urls history.
 *
 * Cost: ~\$0.50/clip (5s 720p Wan 2.6) — 12x more expensive than a still.
 * Separate daily cap (services.fal.video_daily_cap_usd, default \$2/day).
 *
 * Required input:
 *   - draft_id (int)
 *
 * Optional input:
 *   - prompt_override (string)
 *   - duration_seconds (int, max 8 for Wan)
 *   - skip_blotato_upload (bool) — dev-only
 */
class VideoAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    /** $5/workspace/day cap — ~10 video generations at FAL Wan 2.6 720p
     *  ($0.50/clip). The previous $2/day cap tripped on day 5 of normal use
     *  (5 brands × ~1 video/week + redraft headroom). */
    private const DEFAULT_DAILY_CAP_USD = 5.00;

    /** Approx Wan 2.6 5s 720p cost. */
    private const FAL_WAN_USD_PER_VIDEO = 0.50;

    /** Allowed aspect ratios. Anything else is rejected at resolveAspectRatio
     *  to keep FAL + ffmpeg branches finite. '4:5' (IG square-ish) can be
     *  added later but isn't supported by Wan 2.6. */
    private const ALLOWED_ASPECTS = ['9:16', '16:9', '1:1'];

    /** Per-platform default video aspect when the draft does not specify
     *  one. Picked for primary feed surface, not the auxiliary one:
     *    - YouTube: long-form 16:9 (Shorts is bonus, not the bigger surface)
     *    - LinkedIn: feed video 16:9 (LinkedIn Shorts is a tiny audience)
     *    - X: tweet player is 16:9
     *    - TikTok / IG / Threads / Facebook: vertical 9:16 (Reels/feed) */
    private const PLATFORM_ASPECT_DEFAULTS = [
        'tiktok'    => '9:16',
        'instagram' => '9:16',
        'threads'   => '9:16',
        'facebook'  => '9:16',
        'youtube'   => '16:9',
        'linkedin'  => '16:9',
        'x'         => '16:9',
        'twitter'   => '16:9',
    ];

    public function role(): string { return 'video'; }
    public function promptVersion(): string { return 'video.v1.2'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $draftId = $input['draft_id'] ?? null;
        if (! $draftId) {
            throw new InvalidArgumentException('VideoAgent requires draft_id.');
        }

        $draft = Draft::where('id', $draftId)->where('brand_id', $brand->id)->first();
        if (! $draft) {
            return AgentResult::fail('Draft not found.');
        }

        // Platform gate — don't burn video credit on text-only platforms.
        if (! FalAiClient::platformAcceptsVideo($draft->platform)) {
            return AgentResult::fail("Platform '{$draft->platform}' does not accept short-form video — skip VideoAgent on this draft.");
        }

        // Already video? Idempotent no-op. Detect by .mp4 extension or
        // asset_urls history containing a video entry.
        if ($this->draftAlreadyHasVideo($draft)) {
            return AgentResult::ok([
                'draft_id' => $draft->id,
                'asset_url' => $draft->asset_url,
                'note' => 'already-has-video',
            ]);
        }

        // Library-first routing for videos — same shape as DesignerAgent.
        $forceFal = ! empty($input['force_fal']) || ! empty($input['skip_library']);
        $libraryFirst = (bool) config('services.fal.library_first', true);

        if (! $forceFal && $libraryFirst) {
            $picked = app(BrandAssetPicker::class)->pickFor($brand, $draft, 'video');
            if ($picked) {
                /** @var \App\Models\BrandAsset $asset */
                $asset = $picked['asset'];
                try {
                    $blotatoUrl = BlotatoClient::fromConfig()->uploadMediaFromUrl($asset->public_url);
                } catch (\Throwable $e) {
                    Log::warning('VideoAgent: library asset Blotato upload failed; falling back to FAL', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $blotatoUrl = null;
                }

                if ($blotatoUrl) {
                    $newHistory = is_array($draft->asset_urls) ? $draft->asset_urls : [];
                    if ($draft->asset_url && ! in_array($draft->asset_url, $newHistory, true)) {
                        $newHistory[] = $draft->asset_url;
                    }
                    $newHistory[] = $blotatoUrl;
                    $newHistory[] = $asset->public_url;
                    $draft->update([
                        'asset_url' => $blotatoUrl,
                        'asset_urls' => array_values(array_unique($newHistory)),
                    ]);
                    $asset->recordUse();

                    return AgentResult::ok([
                        'draft_id' => $draft->id,
                        'asset_url' => $blotatoUrl,
                        'library_asset_id' => $asset->id,
                        'platform' => $draft->platform,
                        'cost_usd' => 0.0,
                        'distance' => round((float) $picked['distance'], 4),
                        'source' => 'library',
                    ], [
                        'source' => 'library',
                        'cost_usd' => 0.0,
                    ]);
                }
            }
        }

        // Cost circuit breaker — separate from image cap. Filter by provider
        // too so that any future non-FAL video provider (e.g. Runway) doesn't
        // double-count against the FAL cap.
        $cap = (float) config('services.fal.video_daily_cap_usd', self::DEFAULT_DAILY_CAP_USD);
        $spentToday = (float) AiCost::where('workspace_id', $brand->workspace_id)
            ->where('agent_role', $this->role())
            ->where('provider', 'fal')
            ->whereDate('called_at', now()->toDateString())
            ->sum('cost_usd');
        if ($spentToday >= $cap) {
            return AgentResult::fail(sprintf(
                'Daily video budget reached: $%.2f / $%.2f. Resets at midnight UTC. Increase services.fal.video_daily_cap_usd to lift.',
                $spentToday, $cap,
            ));
        }

        $aspect = $this->resolveAspectRatio($draft, $input);
        $prompt = (string) ($input['prompt_override'] ?? $this->buildPrompt($brand, $draft, $aspect));
        $duration = max(3, min(8, (int) ($input['duration_seconds'] ?? 5)));

        try {
            $fal = FalAiClient::fromConfig();
        } catch (\Throwable $e) {
            return AgentResult::fail('FAL.AI not configured: ' . $e->getMessage());
        }

        $stillUrl = $this->stillUrlForKeyframe($draft);

        try {
            $generated = $fal->generateVideo($prompt, array_filter([
                'image_url' => $stillUrl,
                'aspect_ratio' => $aspect,
                'resolution' => '720p',
                'duration' => $duration,
            ]));
        } catch (\Throwable $e) {
            Log::error('VideoAgent: FAL generation failed', [
                'draft_id' => $draft->id,
                'has_still' => (bool) $stillUrl,
                'error' => $e->getMessage(),
            ]);
            return AgentResult::fail('Video generation failed: ' . substr($e->getMessage(), 0, 240));
        }

        $falUrl = $generated['url'];
        $brandedLocalPath = null;

        // Log cost.
        try {
            AiCost::create([
                'workspace_id' => $brand->workspace_id,
                'brand_id' => $brand->id,
                'draft_id' => $draft->id,
                'agent_role' => $this->role(),
                'provider' => 'fal',
                'model_id' => $generated['model'],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'image_count' => 1,
                'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
                'cost_myr' => round(self::FAL_WAN_USD_PER_VIDEO * 4.7, 4),
                'called_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('VideoAgent: cost ledger insert failed', ['error' => $e->getMessage()]);
        }

        // EIAAW house brand: layer voiceover + music + subtitles + logo.
        // Soft-fail: if any step breaks, publish the raw FAL clip — we'd
        // rather ship an unbranded video than nothing on a media-required
        // platform.
        if (EiaawBrandLock::appliesTo($brand) && (bool) config('services.branding.enabled', true)) {
            try {
                $artifact = app(QuoteWriter::class)->distil($draft, $brand);
                $tts = FalTtsClient::fromConfig()->synthesize($artifact['voiceover'], $brand);
                $brandedLocalPath = BrandVideoComposer::fromConfig()->compose(
                    sourceVideoUrl: $falUrl,
                    voiceoverUrl: $tts['audio_url'],
                    chunks: $tts['chunks'],
                    platform: $draft->platform,
                    draftId: $draft->id,
                    aspectRatio: $aspect,
                );
            } catch (\Throwable $e) {
                Log::warning('VideoAgent: brand composition failed; falling back to raw FAL video', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                $brandedLocalPath = null;
            }
        }

        // Publish branded video to public disk so Blotato can fetch it.
        $urlForBlotato = $falUrl;
        if ($brandedLocalPath !== null && is_file($brandedLocalPath)) {
            try {
                $publicRelPath = 'branding/' . $draft->id . '-' . substr(md5(uniqid('', true)), 0, 12) . '.mp4';
                Storage::disk('public')->put($publicRelPath, file_get_contents($brandedLocalPath));
                $urlForBlotato = rtrim((string) config('app.url'), '/') . '/storage/' . $publicRelPath;
                @unlink($brandedLocalPath);
            } catch (\Throwable $e) {
                Log::warning('VideoAgent: failed to publish branded video; falling back to FAL URL', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Re-host on Blotato so it's publishable.
        $finalUrl = $urlForBlotato;
        if (empty($input['skip_blotato_upload'])) {
            try {
                $blotato = BlotatoClient::fromConfig();
                $finalUrl = $blotato->uploadMediaFromUrl($urlForBlotato);
            } catch (\Throwable $e) {
                Log::error('VideoAgent: Blotato media upload failed', [
                    'draft_id' => $draft->id,
                    'fal_url' => $falUrl,
                    'error' => $e->getMessage(),
                ]);
                return AgentResult::fail('Video generated but Blotato upload failed: ' . substr($e->getMessage(), 0, 240));
            }
        }

        // Move existing still into asset_urls history (so the user can
        // see what we built from), put the video URL as primary asset_url.
        $newHistory = is_array($draft->asset_urls) ? $draft->asset_urls : [];
        if ($draft->asset_url && ! in_array($draft->asset_url, $newHistory, true)) {
            $newHistory[] = $draft->asset_url;
        }
        if (! in_array($finalUrl, $newHistory, true)) {
            $newHistory[] = $finalUrl;
        }
        $draft->update([
            'asset_url' => $finalUrl,
            'asset_urls' => array_values($newHistory),
            'video_aspect_ratio' => $aspect, // pin the resolved aspect for replay/audit
        ]);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'asset_url' => $finalUrl,
            'fal_source_url' => $falUrl,
            'platform' => $draft->platform,
            'aspect_ratio' => $aspect,
            'duration_seconds' => $duration,
            'used_keyframe' => (bool) $stillUrl,
            'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
            'latency_ms' => $generated['latency_ms'],
            'prompt' => $prompt,
        ], [
            'model' => $generated['model'],
            'aspect_ratio' => $aspect,
            'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
            'latency_ms' => $generated['latency_ms'],
        ]);
    }

    /**
     * Resolve the aspect ratio for this draft's video. Precedence:
     *   1. $input['aspect_ratio']                 — operator/agent override
     *   2. $draft->video_aspect_ratio             — per-draft pin (set on the
     *      Draft via /agency/drafts edit, or pinned by an earlier VideoAgent run)
     *   3. self::PLATFORM_ASPECT_DEFAULTS         — sensible per-platform default
     *   4. '9:16' fallback                        — unknown platforms get vertical
     *
     * Invalid aspect strings fall through to the platform default rather
     * than throwing — VideoAgent should ship video, not 422.
     */
    private function resolveAspectRatio(Draft $draft, array $input): string
    {
        $candidates = [
            $input['aspect_ratio'] ?? null,
            $draft->video_aspect_ratio ?? null,
            self::PLATFORM_ASPECT_DEFAULTS[strtolower((string) $draft->platform)] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::ALLOWED_ASPECTS, true)) {
                return $candidate;
            }
        }
        return '9:16';
    }

    private function draftAlreadyHasVideo(Draft $draft): bool
    {
        $url = (string) ($draft->asset_url ?? '');
        return $url !== '' && (
            str_ends_with(strtolower($url), '.mp4')
            || str_ends_with(strtolower($url), '.mov')
            || str_ends_with(strtolower($url), '.webm')
            || str_contains($url, '/video/')
        );
    }

    private function stillUrlForKeyframe(Draft $draft): ?string
    {
        $current = (string) ($draft->asset_url ?? '');
        if ($current !== '' && ! $this->draftAlreadyHasVideo($draft)) {
            return $current;
        }
        if (is_array($draft->asset_urls)) {
            foreach ($draft->asset_urls as $u) {
                if (is_string($u) && (str_ends_with(strtolower($u), '.jpg') || str_ends_with(strtolower($u), '.jpeg') || str_ends_with(strtolower($u), '.png') || str_ends_with(strtolower($u), '.webp'))) {
                    return $u;
                }
            }
        }
        return null;
    }

    private function buildPrompt(Brand $brand, Draft $draft, string $aspect = '9:16'): string
    {
        $entry = $draft->calendarEntry;
        $bodyLead = (string) \Illuminate\Support\Str::words(strip_tags((string) $draft->body), 30, ' …');

        $direction = trim((string) ($entry->visual_direction ?? ''));
        $directionHint = $direction !== '' ? " Visual brief: {$direction}." : '';

        $orientation = $this->orientationLabel($aspect); // "vertical 9:16" | "landscape 16:9" | "square 1:1"
        $videoForm = $this->videoFormLabel($draft->platform, $aspect); // "Reel" | "long-form video" | etc.

        // EIAAW-internal workspace: pace to the house editorial motion contract,
        // override platform "fast cuts" defaults that would violate brand.
        if (EiaawBrandLock::appliesTo($brand)) {
            $platformHint = match ($draft->platform) {
                'tiktok'    => "TikTok {$videoForm} {$orientation}, hook in first 1.5s but resolved through composition not jump cuts",
                'instagram' => "Instagram {$videoForm} {$orientation}, slow editorial pacing, deliberate final beat",
                'youtube'   => $aspect === '16:9'
                    ? "YouTube long-form {$orientation}, opening shot doubles as a strong thumbnail, cinematic establishing wide"
                    : "YouTube Shorts {$orientation}, opening frame works as a still thumbnail",
                'threads'   => "Threads short {$orientation}, lo-fi authentic, single-take feel",
                'facebook'  => "Facebook {$videoForm} {$orientation}, slightly slower pacing",
                'linkedin'  => $aspect === '16:9'
                    ? "LinkedIn feed video {$orientation}, professional, no music swells, executive-level framing"
                    : "LinkedIn-native short {$orientation}, professional, no music swells",
                'x', 'twitter' => "X {$videoForm} {$orientation}, scroll-stopping first frame, native-looking",
                default     => "Short-form {$orientation}",
            };

            return sprintf(
                '%d-second %s video for EIAAW Solutions on %s. %s. %s%s Subject: %s. No on-screen text, no captions, no watermarks baked in.',
                5,
                $orientation,
                ucfirst($draft->platform),
                $platformHint,
                EiaawBrandLock::videoDirective(),
                $directionHint,
                $bodyLead,
            );
        }

        // Client workspace path — original platform-aesthetic mapping.
        $platformHint = match ($draft->platform) {
            'tiktok'    => "TikTok-native: hook in first 1.5s, fast cuts, energetic but not chaotic, {$orientation}",
            'instagram' => "Instagram {$videoForm}: clean editorial pacing, brand-consistent palette, {$orientation}, end on a strong final beat",
            'youtube'   => $aspect === '16:9'
                ? "YouTube long-form: cinematic widescreen pacing, thumbnail-strong opening shot, {$orientation}"
                : "YouTube Shorts: thumbnail-first frame readable at small size, {$orientation}",
            'threads'   => "Threads short: lo-fi authentic feel, {$orientation}, no aggressive editing",
            'facebook'  => "Facebook {$videoForm}: similar to Instagram, slightly slower pacing, {$orientation}",
            'linkedin'  => $aspect === '16:9'
                ? "LinkedIn feed video: professional, clear narration cue, no music swells, executive framing, {$orientation}"
                : "LinkedIn-native short: professional, clear narration cue, no music swells, {$orientation}",
            'x', 'twitter' => "X video: scroll-stopping first frame, captioned-by-default mindset, {$orientation}",
            default     => "Short-form {$orientation}, brand-consistent",
        };

        return sprintf(
            '%d-second %s video for "%s" on %s. %s.%s Subject: %s. Realistic camera motion, no on-screen text or watermarks. Anti-slop: avoid stock-video clichés, generic AI swirl effects, and rapid scene cuts every 0.3s.',
            5,
            $orientation,
            $brand->name,
            ucfirst($draft->platform),
            $platformHint,
            $directionHint,
            $bodyLead,
        );
    }

    private function orientationLabel(string $aspect): string
    {
        return match ($aspect) {
            '16:9' => 'landscape 16:9',
            '1:1'  => 'square 1:1',
            default => 'vertical 9:16',
        };
    }

    /** Human label for the video format on each platform — used to make
     *  prompts read naturally ("Instagram Reel" vs "LinkedIn feed video"). */
    private function videoFormLabel(string $platform, string $aspect): string
    {
        $platform = strtolower($platform);
        if ($aspect === '16:9') {
            return match ($platform) {
                'youtube' => 'long-form video',
                'linkedin' => 'feed video',
                'facebook' => 'feed video',
                'instagram' => 'feed video',
                default => 'video',
            };
        }
        // vertical
        return match ($platform) {
            'instagram', 'facebook' => 'Reel',
            'youtube' => 'Short',
            default => 'short-form video',
        };
    }
}
