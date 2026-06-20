<?php

namespace App\Agents;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Draft;
use App\Services\Blotato\BlotatoClient;
use App\Services\Branding\BrandImageStamper;
use App\Services\Branding\InfographicComposer;
use App\Services\Branding\PosterContentWriter;
use App\Services\Branding\QuoteWriter;
use App\Services\Imagery\BrandAssetPicker;
use App\Services\Imagery\DraftSceneBrief;
use App\Services\Imagery\EiaawBrandLock;
use App\Services\Imagery\FalAccountLockedException;
use App\Services\Imagery\FalAiClient;
use App\Services\Imagery\ImageCreativeDirection;
use Illuminate\Support\Facades\Log;
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

    /**
     * Set by buildPrompt() when the chosen path is a poster/infographic that
     * must have its text drawn PROGRAMMATICALLY (InfographicComposer) on the
     * generated text-free background. Read once in handle() after generation,
     * then it drives the FFmpeg drawtext compose step. Null for the photo path
     * and for the legacy "model renders text" rollback.
     *
     * @var array{kind:string,title:string,points?:array<int,string>,panels?:array<int,array{heading:string,bullets:array<int,string>}>,footer?:string}|null
     */
    private ?array $pendingCompose = null;

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

    /** Upper bound on a model-supplied carousel hook-slide visual_direction
     *  before it is interpolated into the FAL prompt (defensive — the value
     *  comes from draft.platform_payload, i.e. LLM output). */
    public const MAX_HOOK_SLIDE_CHARS = 280;

    public function role(): string
    {
        return 'designer';
    }

    // v1.6 — defensive bound on the carousel hook-slide visual_direction read
    // from draft.platform_payload before it enters the prompt (was interpolated
    // unbounded). v1.5 — the image is anchored to the SCRIPTED post content via
    // DraftSceneBrief (hook + distilled quote + CTA + target emotion +
    // visual_direction), not a raw truncated body slice. The poster depicts
    // what the caption actually says, in lockstep with the video built from the
    // same brief. v1.4 retained: ImageCreativeDirection realism contract +
    // structured negative_prompt.
    //
    // NOTE: DesignerAgent makes NO LLM call — image generation goes to FAL.AI.
    // This promptVersion is AUDIT TELEMETRY only (it stamps the audit log + cost
    // ledger so prompt-construction changes are traceable); it does not route an
    // LLM. The LlmGateway inherited from BaseAgent is unused here.
    public function promptVersion(): string
    {
        return 'designer.v1.6';
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

        // Reset any compose payload from a prior run on this instance (agents are
        // resolved fresh per call today, but this keeps handle() re-entrant).
        $this->pendingCompose = null;

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
            $libraryResult = $this->tryLibraryAsset($brand, $draft, 'library');
            if ($libraryResult !== null) {
                return $libraryResult;
            }
            // Fall through to FAL if no usable library asset / Blotato re-host failed.
        }

        // NO daily USD breaker (removed 2026-06-01). Image generation is bound
        // ONLY by the monthly volume cap (max_ai_image_posts_per_month, enforced
        // at the publish gate via max_published_posts_per_month) — the customer
        // self-paces within the month and may spend the whole allowance in a day.
        // The previous per-tier $/day breaker acted as a hidden daily usage cap
        // that stranded drafts mid-month; see [[no-daily-fal-cap]]. Cost is still
        // recorded to the AiCost ledger below for the HQ cost-monitor P&L.

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
        } catch (FalAccountLockedException $e) {
            // FAL is account-locked (balance exhausted). Generating is impossible
            // until a top-up — but a media-required post still shouldn't ship with
            // no image. Degrade to a brand-library asset even when library-first
            // was skipped (internal-prefers-AI / force_fal). If the library has no
            // usable match either, fail with the actionable top-up message so the
            // operator monitor shows the real remedy.
            Log::warning('DesignerAgent: FAL account locked; attempting library fallback', [
                'draft_id' => $draft->id,
            ]);

            $fallback = $this->tryLibraryAsset($brand, $draft, 'library-fallback');
            if ($fallback !== null) {
                return $fallback;
            }

            return AgentResult::fail(
                'FAL.AI account locked (balance exhausted) and no brand-library image to fall back to. '
                .'Top up at fal.ai/dashboard/billing — generation auto-resumes within ~2 min, or upload a brand asset.'
            );
        } catch (\Throwable $e) {
            Log::error('DesignerAgent: FAL generation failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            // Transient/per-request FAL failure: still try the library so a
            // single flaky generation doesn't leave the post image-less.
            $fallback = $this->tryLibraryAsset($brand, $draft, 'library-fallback');
            if ($fallback !== null) {
                return $fallback;
            }

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

        // POSTER / INFOGRAPHIC composition: the generated image is a TEXT-FREE
        // background; draw the headline + panels/points PROGRAMMATICALLY on top
        // (exact spelling — the diffusion model garbles dense text). Soft-fail:
        // if FFmpeg/font/compose breaks, publish the raw background rather than
        // no media (a bare designed background still beats an image-less post).
        if ($isPoster && $this->pendingCompose !== null) {
            try {
                $brandedLocalPath = $this->composePosterArtifact($brand, $draft, $falUrl, $this->pendingCompose);
            } catch (\Throwable $e) {
                Log::warning('DesignerAgent: infographic compose failed; falling back to raw background', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                $brandedLocalPath = null;
            }
        }

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
        // DURABLE disk (R2 in prod, local public for dev) and use that URL as
        // the media source. The branded image lives at
        // <R2_PUBLIC_URL>/branding/<draftid>-<random>.jpg — durably served at
        // smt-assets.eiaawsolutions.com, so the draft preview AND Metricool's
        // publish-time normalize fetch both succeed. Hard-coding the local
        // `public` disk here was the [[brand-asset-storage-ephemeral]] bug's
        // second occurrence: Railway wipes that disk and has no storage:link,
        // so the /storage/ URL 404'd ("Media preview unavailable") and
        // regenerating only re-wrote the same dead URL.
        $urlForBlotato = $falUrl;
        if ($brandedLocalPath !== null && is_file($brandedLocalPath)) {
            try {
                $relPath = 'branding/'.$draft->id.'-'.substr(md5(uniqid('', true)), 0, 12).'.jpg';
                $urlForBlotato = $this->publishArtifact($brandedLocalPath, $relPath);
                @unlink($brandedLocalPath);
            } catch (\Throwable $e) {
                Log::warning('DesignerAgent: failed to publish branded image; falling back to FAL URL', [
                    'draft_id' => $draft->id,
                    'error' => $e->getMessage(),
                ]);
                // urlForBlotato stays as $falUrl — soft fallback.
            }
        }

        // Persist a publish-ready media URL. Provider-aware (see rehostMedia):
        //   metricool → store the public source URL as-is; MetricoolPublisher
        //               re-hosts it via /actions/normalize at publish time using
        //               the ONE shared agency token (no per-workspace key).
        //   blotato   → re-host now through THIS workspace's Blotato account.
        // skip_blotato_upload forces the raw URL through untouched (dev/testing).
        if (empty($input['skip_blotato_upload'])) {
            $rehost = $this->rehostMedia($brand, $urlForBlotato, $draft->id);
            if (! $rehost['ok']) {
                return AgentResult::fail('Image generated but media re-host failed: '.substr($rehost['error'], 0, 200));
            }
            $finalUrl = $rehost['url'];
        } else {
            $finalUrl = $urlForBlotato;
        }

        $draft->update([
            'asset_url' => $finalUrl,
            'asset_urls' => array_values(array_unique(array_merge(
                is_array($draft->asset_urls) ? $draft->asset_urls : [],
                [$finalUrl],
            ))),
            // Stamp the body this still was generated from so a later caption
            // edit can detect the still is stale and regenerate it (e.g. before
            // reusing it as a video keyframe). See Draft::mediaIsStaleForBody().
            'branding_payload' => $this->brandingPayloadWithMediaHash($draft),
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
     * Pick the best brand-library image for this draft and re-host it through
     * the workspace's Blotato account. Returns an ok AgentResult on success,
     * or null when there's no usable match / the Blotato re-host failed (caller
     * then proceeds to FAL, or fails with an actionable message).
     *
     * Used in two places:
     *   - $source='library'          → the normal library-first branch.
     *   - $source='library-fallback' → degradation after a FAL failure
     *     (account locked or transient), so a media-required post still ships
     *     an on-brand image instead of nothing.
     */
    private function tryLibraryAsset(Brand $brand, Draft $draft, string $source): ?AgentResult
    {
        $picked = app(BrandAssetPicker::class)->pickFor($brand, $draft, 'image');
        if (! $picked) {
            return null;
        }

        /** @var BrandAsset $asset */
        $asset = $picked['asset'];

        // Make the asset publish-ready. Provider-aware (see rehostMedia):
        //   metricool → store the asset's public_url as-is; the publisher
        //               normalises it at publish time with the shared token.
        //   blotato   → re-host through THIS WORKSPACE'S Blotato account so
        //               /v2/posts accepts it (the media URL Blotato returns is
        //               scoped to the uploading account — HQ-uploaded media 403s
        //               a customer's createPost; always upload via the brand's
        //               own workspace key).
        $rehost = $this->rehostMedia($brand, $asset->public_url, $draft->id);
        if (! $rehost['ok']) {
            Log::warning('DesignerAgent: library asset re-host failed', [
                'asset_id' => $asset->id,
                'workspace_id' => $brand->workspace_id,
                'source' => $source,
                'error' => $rehost['error'],
            ]);

            return null;
        }
        $publishUrl = $rehost['url'];

        $draft->update([
            'asset_url' => $publishUrl,
            'asset_urls' => array_values(array_unique(array_merge(
                is_array($draft->asset_urls) ? $draft->asset_urls : [],
                [$publishUrl, $asset->public_url],
            ))),
            // See the FAL path above — record which caption this still matches.
            'branding_payload' => $this->brandingPayloadWithMediaHash($draft),
        ]);
        $asset->recordUse();

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'asset_url' => $publishUrl,
            'library_asset_id' => $asset->id,
            'library_asset_label' => $asset->original_filename,
            'platform' => $draft->platform,
            'cost_usd' => 0.0,
            'distance' => round((float) $picked['distance'], 4),
            'source' => $source,
        ], [
            'source' => $source,
            'cost_usd' => 0.0,
        ]);
    }

    /**
     * Merge the current-body fingerprint into the draft's branding_payload so a
     * later caption edit can tell this still is stale (Draft::mediaIsStaleForBody).
     *
     * Reads the LIVE branding_payload off the draft — by the time we persist an
     * asset the distillers (QuoteWriter / PosterContentWriter) have already run
     * for this draft and written quote / voiceover / poster keys, so merging
     * (not replacing) preserves them. Hashes draft->body (unchanged during a
     * Designer run) so the stamp matches the exact caption this still depicts.
     *
     * media_body_hash is stamped UNCONDITIONALLY here: the asset was just
     * generated from a brief built off the CURRENT body, so it depicts the
     * current caption at this instant. Whether it later reads STALE is judged by
     * Draft::mediaIsStaleForBody(), which ALSO requires the distillation (when
     * one exists) to be fresh — that read-side check, not this stamp, is what
     * catches the #436 desync where stale distilled signals were reused under a
     * freshly-stamped media hash. Gating the stamp on distillationIsFreshForBody()
     * would be wrong: the library / raw-photo paths persist media WITHOUT running
     * a distiller, so they'd never satisfy that gate and would loop the
     * regenerate forever.
     */
    private function brandingPayloadWithMediaHash(Draft $draft): array
    {
        $payload = is_array($draft->branding_payload) ? $draft->branding_payload : [];
        $payload['media_body_hash'] = Draft::hashBody($draft->body);

        return $payload;
    }

    /**
     * Make a source media URL publish-ready, the way the active publishing
     * provider needs it. This is the seam that unbroke image generation after
     * the Blotato→Metricool switch: the Designer used to ALWAYS re-host through
     * per-workspace Blotato, which throws for any Metricool-onboarded workspace
     * (those have no Blotato key) — leaving every draft image-less and failing
     * Compliance's platform_publishability check.
     *
     *   metricool → no-op: return the public source URL unchanged. The image
     *               stays at its public origin (fal.media or the brand asset's
     *               public_url); MetricoolPublisher::submit() re-hosts it via
     *               /actions/normalize/image/url at publish time using the ONE
     *               shared agency token. The Publisher contract explicitly owns
     *               provider-side media normalisation — the Designer must not
     *               pre-empt it with a provider-specific re-host.
     *   blotato   → re-host now through THIS workspace's Blotato account (the
     *               legacy rollback path; Blotato rejects external mediaUrls and
     *               scopes hosted media to the uploading account).
     *
     * Returns a uniform result so both call sites (FAL output + library asset)
     * branch identically.
     *
     * @return array{ok:bool, url:string, error:string}
     */
    private function rehostMedia(Brand $brand, string $sourceUrl, int $draftId): array
    {
        $provider = strtolower((string) config('services.publishing.provider', 'metricool')) ?: 'metricool';

        if ($provider !== 'blotato') {
            // Metricool (and any future publisher that normalises at submit):
            // the public source URL is already what the publisher consumes.
            return ['ok' => true, 'url' => $sourceUrl, 'error' => ''];
        }

        try {
            $blotatoUrl = BlotatoClient::forWorkspace($brand->workspace)
                ->uploadMediaFromUrl($sourceUrl);

            return ['ok' => true, 'url' => $blotatoUrl, 'error' => ''];
        } catch (\Throwable $e) {
            Log::error('DesignerAgent: Blotato media re-host failed', [
                'draft_id' => $draftId,
                'workspace_id' => $brand->workspace_id,
                'source_url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'url' => '', 'error' => $e->getMessage()];
        }
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

        // Bound the model-supplied direction before it enters the FAL prompt.
        return mb_substr(
            trim((string) ($first['visual_direction'] ?? $first['title'] ?? '')),
            0,
            self::MAX_HOOK_SLIDE_CHARS,
        );
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
                $this->pendingCompose = $infographic['compose'];

                return $infographic['prompt'];
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
                $this->pendingCompose = $posterPrompt['compose'];

                return $posterPrompt['prompt'];
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
     * Build a SUMMARY-POSTER descriptor. When composition is on (default), the
     * prompt asks the model for a TEXT-FREE poster background and the descriptor
     * carries a `compose` payload so handle() draws the headline + points
     * programmatically (exact spelling). When off (rollback), the prompt bakes
     * the text in via the legacy directive and `compose` is null.
     *
     * Returns null when the draft can't be distilled into enough points — the
     * caller then falls back to the normal photo prompt.
     *
     * @return array{prompt:string, compose:?array{kind:string,title:string,points:array<int,string>}}|null
     */
    private function buildPosterPrompt(Brand $brand, Draft $draft): ?array
    {
        $content = app(PosterContentWriter::class)->distil($draft, $brand);
        if (count($content['points']) < 3) {
            return null;
        }

        if ($this->composeText()) {
            $prompt = sprintf(
                'Poster background for the brand "%s" on %s. %s %s',
                $brand->name,
                ucfirst($draft->platform),
                ImageCreativeDirection::posterBackgroundDirective(),
                $this->posterBrandStyle($brand),
            );

            return [
                'prompt' => $prompt,
                'compose' => [
                    'kind' => 'poster',
                    'title' => $content['title'],
                    'points' => $content['points'],
                ],
            ];
        }

        // Legacy rollback: model renders the text itself.
        return [
            'prompt' => sprintf(
                'Summary poster for the brand "%s" on %s. %s %s %s',
                $brand->name,
                ucfirst($draft->platform),
                ImageCreativeDirection::posterDirective(),
                $this->posterBrandStyle($brand),
                ImageCreativeDirection::posterContentBlock($content['title'], $content['points']),
            ),
            'compose' => null,
        ];
    }

    /**
     * Build a MULTI-PANEL INFOGRAPHIC descriptor. When composition is on
     * (default), the prompt asks for a TEXT-FREE panel-grid background and the
     * descriptor carries a `compose` payload (title + panels + footer) so
     * handle() typesets every word programmatically — guaranteed correct
     * spelling, which the diffusion model cannot deliver at panel density. When
     * off (rollback), the legacy directive bakes the text into the prompt.
     *
     * Returns null when fewer than 2 panels can be distilled — the caller then
     * falls through to the simple poster or photo path.
     *
     * @return array{prompt:string, compose:?array{kind:string,title:string,panels:array<int,array{heading:string,bullets:array<int,string>}>,footer:string}}|null
     */
    private function buildInfographicPrompt(Brand $brand, Draft $draft): ?array
    {
        $content = app(PosterContentWriter::class)->distilPanels($draft, $brand);
        $panels = $content['panels'];
        if (count($panels) < 2) {
            return null;
        }

        if ($this->composeText()) {
            $prompt = sprintf(
                'Infographic background for the brand "%s" on %s. %s %s',
                $brand->name,
                ucfirst($draft->platform),
                ImageCreativeDirection::infographicBackgroundDirective(count($panels)),
                $this->posterBrandStyle($brand),
            );

            return [
                'prompt' => $prompt,
                'compose' => [
                    'kind' => 'infographic',
                    'title' => $content['title'],
                    'panels' => $panels,
                    'footer' => $content['footer'],
                ],
            ];
        }

        // Legacy rollback: model renders the panel text itself.
        return [
            'prompt' => sprintf(
                'Infographic poster for the brand "%s" on %s. %s %s %s',
                $brand->name,
                ucfirst($draft->platform),
                ImageCreativeDirection::infographicDirective(count($panels)),
                $this->posterBrandStyle($brand),
                ImageCreativeDirection::infographicContentBlock($content['title'], $panels, $content['footer']),
            ),
            'compose' => null,
        ];
    }

    /** Whether infographic/poster text is drawn programmatically (default) vs
     *  baked in by the image model (rollback). */
    private function composeText(): bool
    {
        return (bool) config('services.branding.compose_infographics', true)
            && (bool) config('services.branding.enabled', true);
    }

    /**
     * The brand's primary accent as a 6-hex string (no #) for the composer's
     * title bar / footer / rules. EIAAW house brand → deep teal; clients → their
     * own first palette colour; null when neither is available (composer then
     * uses its deep-teal default).
     */
    private function composerAccent(Brand $brand): ?string
    {
        if (EiaawBrandLock::appliesTo($brand)) {
            return '11766A'; // deep teal — references/eiaaw-design-system.md
        }

        $style = $brand->currentStyle;
        if ($style && is_array($style->palette)) {
            foreach ($style->palette as $entry) {
                $hex = is_string($entry) ? $entry : ($entry['hex'] ?? null);
                $hex = is_string($hex) ? ltrim($hex, '#') : '';
                if (preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1) {
                    return strtoupper($hex);
                }
            }
        }

        return null;
    }

    /**
     * Draw the poster/infographic copy on the text-free background via
     * InfographicComposer and return the local path to the composed JPEG.
     * Throws on any compose failure (caller soft-falls to the raw background).
     *
     * @param  array{kind:string,title?:string,points?:array<int,string>,panels?:array<int,array{heading:string,bullets:array<int,string>}>,footer?:string}  $compose
     */
    private function composePosterArtifact(Brand $brand, Draft $draft, string $backgroundUrl, array $compose): string
    {
        $composer = InfographicComposer::fromConfig();
        $accent = $this->composerAccent($brand);
        $opts = $accent !== null ? ['accent' => $accent] : [];

        if (($compose['kind'] ?? '') === 'infographic') {
            return $composer->composeInfographic(
                backgroundImageUrl: $backgroundUrl,
                title: (string) ($compose['title'] ?? ''),
                panels: is_array($compose['panels'] ?? null) ? $compose['panels'] : [],
                footer: (string) ($compose['footer'] ?? ''),
                platform: $draft->platform,
                draftId: $draft->id,
                opts: $opts,
            );
        }

        return $composer->composePoster(
            backgroundImageUrl: $backgroundUrl,
            title: (string) ($compose['title'] ?? ''),
            points: is_array($compose['points'] ?? null) ? $compose['points'] : [],
            platform: $draft->platform,
            draftId: $draft->id,
            opts: $opts,
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
