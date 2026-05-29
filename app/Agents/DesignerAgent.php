<?php

namespace App\Agents;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Draft;
use App\Services\Blotato\BlotatoClient;
use App\Services\Branding\BrandImageStamper;
use App\Services\Branding\PosterContentWriter;
use App\Services\Branding\QuoteWriter;
use App\Services\Imagery\BrandAssetPicker;
use App\Services\Imagery\DraftSceneBrief;
use App\Services\Imagery\EiaawBrandLock;
use App\Services\Imagery\FalAiClient;
use App\Services\Imagery\ImageCreativeDirection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /** Default $1.50/workspace/day before circuit breaker trips. Overridable via config.
     *  At ~$0.003/image (flux/schnell) this allows ~500 image generations per day,
     *  comfortably above the ~35 base + 3x redraft headroom for a 5-brand workspace.
     *  The previous $0.50 default tripped within hours of normal multi-brand use. */
    private const DEFAULT_DAILY_CAP_USD = 1.50;

    /**
     * Per-image cost lookup keyed by FAL model id. Defaults to schnell
     * pricing when model is unknown (errs on under-counting → operator
     * notices on the FAL dashboard, not the other way around).
     */
    private const FAL_PRICING_USD = [
        'fal-ai/nano-banana' => 0.039,
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

    public function role(): string
    {
        return 'designer';
    }

    // v1.5 — the image is now anchored to the SCRIPTED post content via
    // DraftSceneBrief (hook + distilled quote + CTA + target emotion +
    // visual_direction), not a raw truncated body slice. The poster now
    // depicts what the caption actually says, in lockstep with the video
    // built from the same brief. v1.4 retained: ImageCreativeDirection realism
    // contract + structured negative_prompt.
    public function promptVersion(): string
    {
        return 'designer.v1.5';
    }

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
        // pick the best match before burning FAL credit. Escape hatches to
        // skip this and force AI generation:
        //   - $input['force_fal'] = true        (per-call override from UI)
        //   - $input['skip_library'] = true     (legacy alias)
        //   - services.fal.library_first = false (global disable)
        //   - EIAAW-internal brand + services.fal.internal_prefers_ai = true:
        //     the house brand gets a bespoke FAL image from the scripted scene
        //     brief instead of a generic stock-library match, so every internal
        //     post is on-message. Client workspaces keep library-first (they
        //     upload their own brand-correct photography).
        $internalPrefersAi = EiaawBrandLock::appliesTo($brand)
            && (bool) config('services.fal.internal_prefers_ai', true);
        $forceFal = ! empty($input['force_fal']) || ! empty($input['skip_library']) || $internalPrefersAi;
        $libraryFirst = (bool) config('services.fal.library_first', true);

        if (! $forceFal && $libraryFirst) {
            $picked = app(BrandAssetPicker::class)->pickFor($brand, $draft, 'image');
            if ($picked) {
                /** @var BrandAsset $asset */
                $asset = $picked['asset'];

                // Re-host through THIS WORKSPACE'S Blotato account so /v2/posts
                // accepts it. The media URL returned by Blotato is scoped to
                // the uploading account — if HQ uploads it, a customer's
                // createPost() call gets 403 because the account doesn't own
                // that media. Always upload via the brand's workspace's key.
                try {
                    $blotatoUrl = BlotatoClient::forWorkspace($brand->workspace)
                        ->uploadMediaFromUrl($asset->public_url);
                } catch (\Throwable $e) {
                    Log::warning('DesignerAgent: library asset Blotato upload failed; falling back to FAL', [
                        'asset_id' => $asset->id,
                        'workspace_id' => $brand->workspace_id,
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

        // Cost circuit breaker — scope to FAL image spend ONLY. Pre-fix this
        // summed every workspace AiCost row (Anthropic Writer, Voice scorer,
        // embeddings, even Video) into the image cap, causing the breaker to
        // trip after a normal day of LLM use even though no images had been
        // generated. Filter by role + provider so the cap protects what it
        // claims to protect.
        $cap = (float) config('services.fal.daily_cap_usd', self::DEFAULT_DAILY_CAP_USD);
        $spentToday = (float) AiCost::where('workspace_id', $brand->workspace_id)
            ->where('agent_role', $this->role())
            ->where('provider', 'fal')
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
            return AgentResult::fail('FAL.AI not configured: '.$e->getMessage());
        }

        try {
            $generated = $fal->generateImage($prompt, [
                'image_size' => FalAiClient::imageSizeForPlatform($draft->platform),
                // Honoured by negative-capable models (flux/dev, recraft, SD).
                // flux-pro/v1.1 ignores it — the realism block folds the same
                // negatives into the positive prompt for that model.
                'negative_prompt' => ImageCreativeDirection::negativePrompt(),
            ]);
        } catch (\Throwable $e) {
            Log::error('DesignerAgent: FAL generation failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            return AgentResult::fail('Image generation failed: '.substr($e->getMessage(), 0, 200));
        }

        $falUrl = $generated['url'];
        $brandedLocalPath = null;

        // Skip the quote-stamp when this draft was rendered as a summary poster
        // or multi-panel infographic — those already carry headings + points as
        // text, so stamping the quote panel on top would double-up the text.
        // The poster/infographic IS the branded artefact.
        $entry = $draft->calendarEntry;
        $isPoster = FalAiClient::modelUsesAspectRatio($generated['model'])
            && (ImageCreativeDirection::isPosterFormat($entry?->format, $entry?->pillar, $entry?->visual_direction)
                || ImageCreativeDirection::isInfographicFormat($entry?->format, $entry?->pillar, $entry?->visual_direction));

        // EIAAW house brand: stamp the FAL still with a Claude-distilled
        // positive quote + logo + "Powered by EIAAW Solutions" tag. Soft-fail:
        // if anything in the brand layer breaks, we publish the raw FAL image
        // — better than no media on a media-required platform.
        if (! $isPoster && EiaawBrandLock::appliesTo($brand) && (bool) config('services.branding.enabled', true)) {
            try {
                $artifact = app(QuoteWriter::class)->distil($draft, $brand);
                $brandedLocalPath = BrandImageStamper::fromConfig()->stamp(
                    sourceImageUrl: $falUrl,
                    quote: $artifact['quote'],
                    platform: $draft->platform,
                    draftId: $draft->id,
                );
            } catch (\Throwable $e) {
                Log::warning('DesignerAgent: brand stamp failed; falling back to raw FAL image', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                $brandedLocalPath = null;
            }
        }

        // Log cost so the circuit breaker on the next call sees it.
        $costUsd = self::priceFor($generated['model']);

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
                'cost_usd' => $costUsd,
                'cost_myr' => round($costUsd * 4.7, 4),
                'called_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DesignerAgent: cost ledger insert failed', ['error' => $e->getMessage()]);
        }

        // If we successfully stamped a branded version, publish it to the
        // public disk and use that URL for Blotato. Branded image lives at
        // <APP_URL>/storage/branding/<draftid>-<random>.jpg — discoverable
        // only by direct path, served once to Blotato then garbage-collected
        // by the existing storage:link cleanup job.
        $urlForBlotato = $falUrl;
        if ($brandedLocalPath !== null && is_file($brandedLocalPath)) {
            try {
                $publicRelPath = 'branding/'.$draft->id.'-'.substr(md5(uniqid('', true)), 0, 12).'.jpg';
                Storage::disk('public')->put($publicRelPath, file_get_contents($brandedLocalPath));
                $urlForBlotato = rtrim((string) config('app.url'), '/').'/storage/'.$publicRelPath;
                @unlink($brandedLocalPath);
            } catch (\Throwable $e) {
                Log::warning('DesignerAgent: failed to publish branded image; falling back to FAL URL', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                // urlForBlotato stays as $falUrl — soft fallback.
            }
        }

        // Re-host on Blotato (this workspace's account) unless explicitly skipped.
        $finalUrl = $urlForBlotato;
        if (empty($input['skip_blotato_upload'])) {
            try {
                $blotato = BlotatoClient::forWorkspace($brand->workspace);
                $finalUrl = $blotato->uploadMediaFromUrl($urlForBlotato);
            } catch (\Throwable $e) {
                Log::error('DesignerAgent: Blotato media upload failed', [
                    'draft_id' => $draft->id,
                    'workspace_id' => $brand->workspace_id,
                    'fal_url' => $falUrl,
                    'error' => $e->getMessage(),
                ]);

                return AgentResult::fail('Image generated but Blotato upload failed: '.substr($e->getMessage(), 0, 200));
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
    /**
     * The first (hook) carousel slide's visual_direction, if the Writer
     * (v1.5+) produced a slide arc in draft.platform_payload. Empty string
     * otherwise — non-carousel drafts and pre-v1.5 drafts both degrade
     * gracefully to the normal single-image prompt.
     */
    private function hookSlideDirection(Draft $draft): string
    {
        $payload = $draft->platform_payload;
        $slides = is_array($payload['carousel_slides'] ?? null) ? $payload['carousel_slides'] : [];
        $first = $slides[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        return trim((string) ($first['visual_direction'] ?? $first['title'] ?? ''));
    }

    private function buildPrompt(Brand $brand, Draft $draft): string
    {
        $entry = $draft->calendarEntry;
        $activeModel = (string) config('services.fal.image_model', 'fal-ai/nano-banana');

        // Text-capable model required for any baked-in-text poster/infographic.
        $textCapable = FalAiClient::modelUsesAspectRatio($activeModel);

        // INFOGRAPHIC path (richest): carousel posts — and rich educational
        // single-image posts — render as a dense multi-panel explainer poster
        // (title bar → labelled panels with mini-illustrations + bullets →
        // footer), mirroring how a human would design the carousel as one
        // shareable infographic. Tried first because it best represents a
        // multi-section post.
        if ($textCapable
            && ImageCreativeDirection::isInfographicFormat($entry?->format, $entry?->pillar, $entry?->visual_direction)) {
            $infographic = $this->buildInfographicPrompt($brand, $draft);
            if ($infographic !== null) {
                return $infographic;
            }
            // null = couldn't build >= 2 panels → fall through.
        }

        // SUMMARY-POSTER path: for single-image educational / listicle /
        // quote-card formats on a text-capable model, render a designed poster
        // (headline + 3-5 key points as legible text) instead of a text-free
        // photo. Gated so photo formats and flux-family models are untouched.
        if ($textCapable
            && ImageCreativeDirection::isPosterFormat($entry?->format, $entry?->pillar, $entry?->visual_direction)) {
            $posterPrompt = $this->buildPosterPrompt($brand, $draft);
            if ($posterPrompt !== null) {
                return $posterPrompt;
            }
            // null = couldn't distil enough points → fall through to photo.
        }

        // Anchor the image to the SCRIPTED post content (hook, distilled quote,
        // CTA, target emotion, visual_direction) — not a raw slice of the body.
        // This is what keeps the poster about the same thing the caption says,
        // and in lockstep with the video built from the same brief.
        $sceneBrief = DraftSceneBrief::for($draft, 24);
        if ($sceneBrief === '') {
            // No scripted signal at all (empty draft) — degrade to a topic line.
            $sceneBrief = 'Depict the topic of this post (do NOT render text in the image): '
                .(string) Str::words(strip_tags((string) $draft->body), 24, ' …').'.';
        }

        // Carousel-aware: when the Writer produced a slide arc, anchor the hero
        // image to the FIRST (hook) slide's visual direction so the cover frame
        // sets up the carousel narrative rather than illustrating the whole
        // body generically. We still render one image here (the cover); a
        // future per-slide pass can iterate platform_payload.carousel_slides.
        $hookSlide = $this->hookSlideDirection($draft);
        if ($hookSlide !== '') {
            $sceneBrief .= " Carousel cover (hook slide): {$hookSlide}.";
        }

        // Text-eager models (Nano Banana / Gemini) need a firmer no-text
        // instruction on the PHOTO path — they render legible text readily, and
        // our pipeline stamps the quote programmatically instead. Empty for
        // flux-family. ($activeModel computed at the top of buildPrompt.)
        $noTextReinforcement = ImageCreativeDirection::noTextReinforcementFor($activeModel);
        if ($noTextReinforcement !== '') {
            $sceneBrief .= ' '.$noTextReinforcement;
        }

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
                'Editorial photographic image (NOT a graphic design, NOT an infographic, NOT a typography poster) for EIAAW Solutions on %s. %s. %s %s %s %s ABSOLUTELY NO TEXT in the image: no letters, no words, no captions, no headlines, no numbers, no list bullets, no logos, no watermarks, no signs, no labels, no UI mockups, no screen text. The image must read as a real photograph or stylised illustration. If your model wants to add text, replace it with a photographic subject instead.',
                ucfirst($draft->platform),
                $platformComposition,
                EiaawBrandLock::imageDirective(),
                EiaawBrandLock::typographyHint(),
                $sceneBrief,
                ImageCreativeDirection::realismBlock(),
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
            'Editorial photographic image (NOT a graphic design, NOT an infographic, NOT a typography poster) for the brand "%s" on %s. %s.%s %s %s ABSOLUTELY NO TEXT in the image: no letters, no words, no captions, no headlines, no numbers, no list bullets, no logos, no watermarks, no signs, no labels, no UI mockups, no screen text. The image must read as a real photograph or stylised illustration. If the model wants to add text, replace with a photographic subject instead. Anti-slop: avoid generic purple/magenta gradient backgrounds, radial glows, stock-photo poses, clip-art icons, and AI-swirl effects.',
            $brand->name,
            ucfirst($draft->platform),
            $platformAesthetic,
            $paletteHint,
            $sceneBrief,
            ImageCreativeDirection::realismBlock(),
        );
    }

    /**
     * Build a SUMMARY-POSTER prompt: a designed graphic whose headline + key
     * points are rendered as legible text by the (text-capable) model. Returns
     * null when the draft can't be distilled into enough points — the caller
     * then falls back to the normal photo prompt rather than shipping an empty
     * poster.
     */
    private function buildPosterPrompt(Brand $brand, Draft $draft): ?string
    {
        $content = app(PosterContentWriter::class)->distil($draft, $brand);
        if (count($content['points']) < 3) {
            return null;
        }

        return sprintf(
            'Summary poster for the brand "%s" on %s. %s %s %s',
            $brand->name,
            ucfirst($draft->platform),
            ImageCreativeDirection::posterDirective(),
            $this->posterBrandStyle($brand),
            ImageCreativeDirection::posterContentBlock($content['title'], $content['points']),
        );
    }

    /**
     * Build a MULTI-PANEL INFOGRAPHIC prompt: title bar + labelled panels (each
     * with bullets + a mini-illustration hint) + footer takeaway, rendered as
     * legible text by the text-capable model. Returns null when fewer than 2
     * panels can be distilled — the caller then falls through to the simple
     * poster or photo path.
     */
    private function buildInfographicPrompt(Brand $brand, Draft $draft): ?string
    {
        $content = app(PosterContentWriter::class)->distilPanels($draft, $brand);
        $panels = $content['panels'];
        if (count($panels) < 2) {
            return null;
        }

        return sprintf(
            'Infographic poster for the brand "%s" on %s. %s %s %s',
            $brand->name,
            ucfirst($draft->platform),
            ImageCreativeDirection::infographicDirective(count($panels)),
            $this->posterBrandStyle($brand),
            ImageCreativeDirection::infographicContentBlock($content['title'], $panels, $content['footer']),
        );
    }

    /**
     * Brand-style clause for poster/infographic prompts. EIAAW house brand
     * keeps its palette/typography spine; clients get their own palette so the
     * designed graphic stays on-brand.
     */
    private function posterBrandStyle(Brand $brand): string
    {
        if (EiaawBrandLock::appliesTo($brand)) {
            return 'Brand style: '.EiaawBrandLock::typographyHint()
                .' Warm-cream background, deep-teal accents, near-black ink — no neon, no purple, no dark navy.';
        }

        $style = $brand->currentStyle;
        if ($style && is_array($style->palette) && ! empty($style->palette)) {
            $hexes = collect($style->palette)
                ->map(fn ($h) => is_string($h) ? $h : ($h['hex'] ?? null))
                ->filter()
                ->take(4)
                ->implode(', ');
            if ($hexes !== '') {
                return "Brand palette for the poster: {$hexes}.";
            }
        }

        return '';
    }
}
