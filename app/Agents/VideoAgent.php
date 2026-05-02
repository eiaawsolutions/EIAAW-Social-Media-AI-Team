<?php

namespace App\Agents;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Draft;
use App\Services\Blotato\BlotatoClient;
use App\Services\Imagery\FalAiClient;
use Illuminate\Support\Facades\Log;
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

    private const DEFAULT_DAILY_CAP_USD = 2.00;

    /** Approx Wan 2.6 5s 720p cost. */
    private const FAL_WAN_USD_PER_VIDEO = 0.50;

    public function role(): string { return 'video'; }
    public function promptVersion(): string { return 'video.v1.0'; }

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

        // Cost circuit breaker — separate from image cap.
        $cap = (float) config('services.fal.video_daily_cap_usd', self::DEFAULT_DAILY_CAP_USD);
        $spentToday = (float) AiCost::where('workspace_id', $brand->workspace_id)
            ->where('agent_role', $this->role())
            ->whereDate('called_at', now()->toDateString())
            ->sum('cost_usd');
        if ($spentToday >= $cap) {
            return AgentResult::fail(sprintf(
                'Daily video budget reached: $%.2f / $%.2f. Resets at midnight UTC. Increase services.fal.video_daily_cap_usd to lift.',
                $spentToday, $cap,
            ));
        }

        $prompt = (string) ($input['prompt_override'] ?? $this->buildPrompt($brand, $draft));
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
                'aspect_ratio' => '9:16',
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

        // Log cost.
        try {
            AiCost::create([
                'workspace_id' => $brand->workspace_id,
                'brand_id' => $brand->id,
                'agent_role' => $this->role(),
                'provider' => 'fal',
                'model_id' => $generated['model'],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
                'cost_myr' => round(self::FAL_WAN_USD_PER_VIDEO * 4.7, 4),
                'called_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('VideoAgent: cost ledger insert failed', ['error' => $e->getMessage()]);
        }

        // Re-host on Blotato so it's publishable.
        $finalUrl = $falUrl;
        if (empty($input['skip_blotato_upload'])) {
            try {
                $blotato = BlotatoClient::fromConfig();
                $finalUrl = $blotato->uploadMediaFromUrl($falUrl);
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
        ]);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'asset_url' => $finalUrl,
            'fal_source_url' => $falUrl,
            'platform' => $draft->platform,
            'duration_seconds' => $duration,
            'used_keyframe' => (bool) $stillUrl,
            'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
            'latency_ms' => $generated['latency_ms'],
            'prompt' => $prompt,
        ], [
            'model' => $generated['model'],
            'cost_usd' => self::FAL_WAN_USD_PER_VIDEO,
            'latency_ms' => $generated['latency_ms'],
        ]);
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

    private function buildPrompt(Brand $brand, Draft $draft): string
    {
        $entry = $draft->calendarEntry;
        $bodyLead = (string) \Illuminate\Support\Str::words(strip_tags((string) $draft->body), 30, ' …');

        $direction = trim((string) ($entry->visual_direction ?? ''));
        $directionHint = $direction !== '' ? " Visual brief: {$direction}." : '';

        $platformHint = match ($draft->platform) {
            'tiktok' => 'TikTok-native: hook in first 1.5s, fast cuts, energetic but not chaotic, 9:16 vertical',
            'instagram' => 'Instagram Reel: clean editorial pacing, brand-consistent palette, 9:16 vertical, end on a strong final beat',
            'youtube' => 'YouTube Shorts: thumbnail-first frame should be readable at small size, 9:16 vertical',
            'threads' => 'Threads short: lo-fi authentic feel, vertical 9:16, no aggressive editing',
            'facebook' => 'Facebook Reel: similar to Instagram, slightly slower pacing',
            'linkedin' => 'LinkedIn-native short: professional, clear narration cue, no music swells, vertical 9:16',
            default => 'Short-form vertical 9:16, brand-consistent',
        };

        return sprintf(
            '%d-second short-form vertical video for "%s" on %s. %s.%s Subject: %s. Realistic camera motion, no on-screen text or watermarks. Anti-slop: avoid stock-video clichés, generic AI swirl effects, and rapid scene cuts every 0.3s.',
            5,
            $brand->name,
            ucfirst($draft->platform),
            $platformHint,
            $directionHint,
            $bodyLead,
        );
    }
}
