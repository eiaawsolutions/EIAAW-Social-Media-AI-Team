<?php

namespace App\Agents;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\Draft;
use App\Services\Blotato\BlotatoClient;
use App\Services\Imagery\BrandAssetPicker;
use App\Services\Imagery\EiaawBrandLock;
use App\Services\Imagery\FalAiClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Generates one image per Draft via FAL.AI, then re-hosts it on Blotato so
 * it can be attached to /v2/posts at publish time. Posts without imagery
 * underperform 5-10x on every visual platform — this is the agent that
 * makes the difference between "AI text drafts" and "shipped social media".
 *
 * Flow:
 *   1. Build the FAL prompt from the brand's visual_direction column on
 *      the calendar entry, the brand's palette/typography from BrandStyle,
 *      and the platform's aspect ratio.
 *   2. Cost circuit breaker: if today's ai_costs total for this workspace
 *      already exceeds the configured cap, fail loud rather than burn $.
 *   3. Call FalAiClient::generateImage. Capture latency + cost.
 *   4. Upload the resulting fal.media URL to Blotato /v2/media to convert
 *      it into a Blotato-hosted URL (Blotato rejects external mediaUrls).
 *   5. Persist the Blotato URL on draft.asset_url so SubmitScheduledPost
 *      attaches it at publish time.
 *
 * Required input:
 *   - draft_id (int)
 *
 * Optional input:
 *   - prompt_override (string) — bypass auto-generated prompt entirely.
 *   - skip_blotato_upload (bool) — for dev/testing; saves the fal.media URL.
 */
class DesignerAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    /** Default $0.50/workspace/day before circuit breaker trips. Overridable via config. */
    private const DEFAULT_DAILY_CAP_USD = 0.50;

    /**
     * Per-image cost lookup keyed by FAL model id. Defaults to schnell
     * pricing when model is unknown (errs on under-counting → operator
     * notices on the FAL dashboard, not the other way around).
     */
    private const FAL_PRICING_USD = [
        'fal-ai/flux/schnell' => 0.003,
        'fal-ai/flux/dev' => 0.025,
        'fal-ai/flux-pro/v1.1' => 0.04,
        'fal-ai/recraft-v3' => 0.04,
        'fal-ai/imagen4/preview' => 0.025,
    ];

    private static function priceFor(string $model): float
    {
        return self::FAL_PRICING_USD[$model] ?? 0.003;
    }

    public function role(): string { return 'designer'; }
    public function promptVersion(): string { return 'designer.v1.2'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $draftId = $input['draft_id'] ?? null;
        if (! $draftId) {
            throw new InvalidArgumentException('DesignerAgent requires draft_id.');
        }

        $draft = Draft::where('id', $draftId)->where('brand_id', $brand->id)->first();
        if (! $draft) {
            return AgentResult::fail('Draft not found.');
        }

        // Already has an asset? No-op (idempotent). Re-running is allowed
        // if the user explicitly clears asset_url (e.g. "regenerate image").
        if (! empty($draft->asset_url)) {
            return AgentResult::ok([
                'draft_id' => $draft->id,
                'asset_url' => $draft->asset_url,
                'note' => 'already-has-asset',
            ]);
        }

        // Library-first routing. If the brand has uploaded assets, semantically
        // pick the best match before burning FAL credit. Three escape hatches
        // for the operator to skip this and force AI generation:
        //   - $input['force_fal'] = true        (per-call override from UI)
        //   - $input['skip_library'] = true     (legacy alias)
        //   - DesignerAgent\Pickerss disabled via config (services.fal.library_first = false)
        $forceFal = ! empty($input['force_fal']) || ! empty($input['skip_library']);
        $libraryFirst = (bool) config('services.fal.library_first', true);

        if (! $forceFal && $libraryFirst) {
            $picked = app(BrandAssetPicker::class)->pickFor($brand, $draft, 'image');
            if ($picked) {
                /** @var \App\Models\BrandAsset $asset */
                $asset = $picked['asset'];

                // Re-host through Blotato so /v2/posts accepts it.
                try {
                    $blotatoUrl = BlotatoClient::fromConfig()->uploadMediaFromUrl($asset->public_url);
                } catch (\Throwable $e) {
                    Log::warning('DesignerAgent: library asset Blotato upload failed; falling back to FAL', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $blotatoUrl = null;
                }

                if ($blotatoUrl) {
                    $draft->update([
                        'asset_url' => $blotatoUrl,
                        'asset_urls' => array_values(array_unique(array_merge(
                            is_array($draft->asset_urls) ? $draft->asset_urls : [],
                            [$blotatoUrl, $asset->public_url],
                        ))),
                    ]);
                    $asset->recordUse();

                    return AgentResult::ok([
                        'draft_id' => $draft->id,
                        'asset_url' => $blotatoUrl,
                        'library_asset_id' => $asset->id,
                        'library_asset_label' => $asset->original_filename,
                        'platform' => $draft->platform,
                        'cost_usd' => 0.0,
                        'distance' => round((float) $picked['distance'], 4),
                        'source' => 'library',
                    ], [
                        'source' => 'library',
                        'cost_usd' => 0.0,
                    ]);
                }
                // Fall through to FAL if Blotato re-host failed.
            }
        }

        // Cost circuit breaker — refuse if we're at/over the daily cap.
        $cap = (float) config('services.fal.daily_cap_usd', self::DEFAULT_DAILY_CAP_USD);
        $spentToday = (float) AiCost::where('workspace_id', $brand->workspace_id)
            ->whereDate('called_at', now()->toDateString())
            ->sum('cost_usd');
        if ($spentToday >= $cap) {
            return AgentResult::fail(sprintf(
                'Daily image budget reached: $%.2f / $%.2f. Resets at midnight UTC. Increase services.fal.daily_cap_usd to lift.',
                $spentToday, $cap,
            ));
        }

        // Build the FAL prompt from brand voice + entry visual direction.
        $prompt = (string) ($input['prompt_override'] ?? $this->buildPrompt($brand, $draft));

        try {
            $fal = FalAiClient::fromConfig();
        } catch (\Throwable $e) {
            return AgentResult::fail('FAL.AI not configured: ' . $e->getMessage());
        }

        try {
            $generated = $fal->generateImage($prompt, [
                'image_size' => FalAiClient::imageSizeForPlatform($draft->platform),
            ]);
        } catch (\Throwable $e) {
            Log::error('DesignerAgent: FAL generation failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            return AgentResult::fail('Image generation failed: ' . substr($e->getMessage(), 0, 200));
        }

        $falUrl = $generated['url'];

        // Log cost so the circuit breaker on the next call sees it.
        $costUsd = self::priceFor($generated['model']);

        try {
            AiCost::create([
                'workspace_id' => $brand->workspace_id,
                'brand_id' => $brand->id,
                'agent_role' => $this->role(),
                'provider' => 'fal',
                'model_id' => $generated['model'],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => $costUsd,
                'cost_myr' => round($costUsd * 4.7, 4),
                'called_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DesignerAgent: cost ledger insert failed', ['error' => $e->getMessage()]);
        }

        // Re-host on Blotato unless explicitly skipped.
        $finalUrl = $falUrl;
        if (empty($input['skip_blotato_upload'])) {
            try {
                $blotato = BlotatoClient::fromConfig();
                $finalUrl = $blotato->uploadMediaFromUrl($falUrl);
            } catch (\Throwable $e) {
                Log::error('DesignerAgent: Blotato media upload failed', [
                    'draft_id' => $draft->id,
                    'fal_url' => $falUrl,
                    'error' => $e->getMessage(),
                ]);
                return AgentResult::fail('Image generated but Blotato upload failed: ' . substr($e->getMessage(), 0, 200));
            }
        }

        $draft->update([
            'asset_url' => $finalUrl,
            'asset_urls' => array_values(array_unique(array_merge(
                is_array($draft->asset_urls) ? $draft->asset_urls : [],
                [$finalUrl],
            ))),
        ]);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'asset_url' => $finalUrl,
            'fal_source_url' => $falUrl,
            'platform' => $draft->platform,
            'image_size' => FalAiClient::imageSizeForPlatform($draft->platform),
            'cost_usd' => $costUsd,
            'latency_ms' => $generated['latency_ms'],
            'prompt' => $prompt,
            'model' => $generated['model'],
            'source' => 'fal',
        ], [
            'model' => $generated['model'],
            'cost_usd' => $costUsd,
            'latency_ms' => $generated['latency_ms'],
        ]);
    }

    /**
     * Compose the image prompt:
     *   - Brand-style palette + typography (concrete art-direction signals)
     *   - Calendar entry's visual_direction sentence (the Strategist's brief)
     *   - The draft body's first sentence (subject anchor)
     *   - Aspect-ratio + platform-specific aesthetic hints
     */
    private function buildPrompt(Brand $brand, Draft $draft): string
    {
        $entry = $draft->calendarEntry;
        $bodyLead = (string) \Illuminate\Support\Str::words(strip_tags((string) $draft->body), 24, ' …');

        $direction = trim((string) ($entry->visual_direction ?? ''));
        $directionHint = $direction !== '' ? " Visual direction from strategist: {$direction}." : '';

        $platformComposition = match ($draft->platform) {
            'instagram', 'facebook' => 'square 1:1 composition, generous negative space',
            'linkedin' => 'square 1:1, professional B2B feel without stock-photo clichés',
            'tiktok', 'threads' => 'vertical 9:16 composition with a strong focal subject',
            'youtube' => 'cinematic 16:9 landscape, hero subject readable at small thumbnail size',
            'pinterest' => 'tall 2:3 pinnable composition, lifestyle-document aesthetic',
            'x' => 'sharp graphic with a single high-contrast focal point',
            default => 'clean platform-appropriate composition',
        };

        // EIAAW-internal workspace: anchor to the locked house style instead
        // of the generic palette/aesthetic hint. Source of truth:
        // ~/.claude/skills/full-stack-engineer/references/eiaaw-design-system.md.
        if (EiaawBrandLock::appliesTo($brand)) {
            return sprintf(
                'Editorial photographic image (NOT a graphic design, NOT an infographic, NOT a typography poster) for EIAAW Solutions on %s. %s. %s %s%s Topic interpretation: %s. ABSOLUTELY NO TEXT in the image: no letters, no words, no captions, no headlines, no numbers, no list bullets, no logos, no watermarks, no signs, no labels, no UI mockups, no screen text. The image must read as a real photograph or stylised illustration. If your model wants to add text, replace it with a photographic subject instead.',
                ucfirst($draft->platform),
                $platformComposition,
                EiaawBrandLock::imageDirective(),
                EiaawBrandLock::typographyHint(),
                $directionHint,
                $bodyLead,
            );
        }

        // Client workspace path — uses the brand's own palette + voice.
        $style = $brand->currentStyle;
        $paletteHint = '';
        if ($style && is_array($style->palette) && ! empty($style->palette)) {
            $hexes = collect($style->palette)
                ->map(fn ($h) => is_string($h) ? $h : ($h['hex'] ?? null))
                ->filter()
                ->take(4)
                ->implode(', ');
            if ($hexes !== '') {
                $paletteHint = " Brand palette: {$hexes}.";
            }
        }

        $platformAesthetic = match ($draft->platform) {
            'instagram', 'facebook' => 'editorial-grade square composition, generous negative space, premium look',
            'linkedin' => 'professional B2B aesthetic, clean and minimal, no stock-photo cliches',
            'tiktok', 'threads' => 'vertical composition, bold readable typography, energetic but not loud',
            'youtube' => 'cinematic landscape composition, strong hero subject, thumbnail-readable at small size',
            'pinterest' => 'tall pinnable composition, lifestyle-document aesthetic',
            'x' => 'sharp graphic, high contrast, single focal point',
            default => 'clean and brand-aligned',
        };

        return sprintf(
            'Editorial photographic image (NOT a graphic design, NOT an infographic, NOT a typography poster) for the brand "%s" on %s. %s.%s%s Topic interpretation: %s. ABSOLUTELY NO TEXT in the image: no letters, no words, no captions, no headlines, no numbers, no list bullets, no logos, no watermarks, no signs, no labels, no UI mockups, no screen text. The image must read as a real photograph or stylised illustration. If the model wants to add text, replace with a photographic subject instead. Anti-slop: avoid generic purple/magenta gradient backgrounds, radial glows, stock-photo poses, clip-art icons, and AI-swirl effects.',
            $brand->name,
            ucfirst($draft->platform),
            $platformAesthetic,
            $paletteHint,
            $directionHint,
            $bodyLead,
        );
    }
}
