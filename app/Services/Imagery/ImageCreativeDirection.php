<?php

namespace App\Services\Imagery;

/**
 * Canonical creative-direction contract for AI visual generation — both
 * still images (FAL Flux) and short-form video (FAL Wan 2.6).
 *
 * Encodes the realism + anti-AI-aesthetic rules every DesignerAgent and
 * VideoAgent prompt must carry so generated social creative looks naturally
 * shot, organic, platform-native, and publishable with minimal editing —
 * instead of the glossy, plastic, over-cinematic "obvious AI" look that tanks
 * engagement.
 *
 * STILLS (Flux) — per FAL Flux optimisation rules: tangible texture, specific
 * natural lighting states, lens metadata, organic composition. Deliberately
 * does NOT use "photorealistic" or "4K" (those push Flux toward the glossy AI
 * look the contract is fighting):
 *   - realismBlock()  → positive clauses appended to the image prompt. The
 *     only steering signal that works on the default model
 *     (fal-ai/flux-pro/v1.1, which has NO negative_prompt field — verified
 *     against FAL's schema 2026-05-28). The "avoid …" clauses here are the
 *     in-prompt equivalent of a negative prompt for that model.
 *   - negativePrompt() → a structured negative passed to generateImage.
 *     flux-pro/v1.1 silently ignores it; negative-capable models a workspace
 *     may flip to (flux/dev, recraft-v3, SD-family) honour it.
 *
 * VIDEO (Wan 2.6) — per FAL Wan optimisation rules: kinetic descriptions,
 * camera-motion parameters, 9:16-native phrasing, believable physics:
 *   - videoRealismBlock()  → positive kinetic + camera-motion clauses appended
 *     to the video prompt.
 *   - videoNegativePrompt() → structured negative passed to generateVideo.
 *     Wan 2.5/2.6 DOES honour negative_prompt (max 500 chars — this string
 *     stays well under that).
 *
 * Single source of truth: the EIAAW house-brand path and the client path in
 * both DesignerAgent::buildPrompt() and VideoAgent::buildPrompt() consume
 * these, so the realism contract never drifts across agents or brand paths.
 */
final class ImageCreativeDirection
{
    /**
     * Positive realism + execution clauses. Covers, per the creative-direction
     * spec: lighting, lens style, composition, camera angle, color grading,
     * emotion, natural imperfections, texture realism, social-native aesthetic,
     * and human anatomy guidance — plus the in-prompt "avoid" list that stands
     * in for a negative prompt on models that lack the field.
     */
    public static function realismBlock(): string
    {
        return implode(' ', [
            // Realism execution — what to DO.
            'Shot on a full-frame camera with a natural prime lens (35mm or 50mm), realistic shallow depth of field and authentic background separation.',
            'Natural daylight or soft window light with believable directional shadows; colour grading is editorial and true-to-life, not over-processed.',
            'Organic, slightly off-centre framing as if captured by a real photographer — not perfectly symmetrical, not robotic composition.',
            'Real surface texture and material detail: visible grain, fabric weave, skin pores and fine imperfections kept intact (no artificial smoothing).',
            'Emotionally authentic — the scene should feel like a real, unstaged moment, premium but human, in the register of modern creator and editorial brand photography.',
            // Human-presence guidance — only relevant when people appear, harmless otherwise.
            'If people appear: natural posture, believable eye direction, correct anatomy and exactly five fingers per hand, genuine expressions — never uncanny, never synthetic perfection.',
            // In-prompt negative (works on no-negative-field models like flux-pro v1.1).
            'AVOID the following: hyper-glossy or plastic AI skin, over-smoothed faces, waxy or CGI 3D-render look, unreal HDR lighting, fake bokeh, extreme symmetry, over-sharpening, cartoon styling, distorted or extra fingers, bad anatomy, unreal cinematic glow, artificial reflections, and visible AI artifacts.',
        ]);
    }

    /**
     * Structured negative prompt for models that expose a negative_prompt
     * field. Mirrors the spec's NEGATIVE PROMPT RULES. Comma-separated tokens
     * are what SD/flux-dev-style models parse most reliably.
     */
    public static function negativePrompt(): string
    {
        return implode(', ', [
            'CGI', '3D render', 'plastic skin', 'waxy skin', 'over-smoothed skin',
            'hyper glossy', 'unreal symmetry', 'cartoon', 'illustration of a render',
            'bad hands', 'extra fingers', 'missing fingers', 'deformed hands',
            'bad anatomy', 'deformed face', 'disfigured', 'mutated',
            'AI artifacts', 'over-sharpened', 'hyper sharpness', 'over-processed lighting',
            'unreal cinematic glow', 'fake bokeh', 'synthetic expression', 'dead eyes',
            'unreal reflections', 'oversaturated', 'low quality', 'jpeg artifacts',
            'watermark', 'signature', 'text', 'logo',
        ]);
    }

    /**
     * Extra no-text reinforcement for text-eager models (Nano Banana / Gemini
     * / Imagen render legible text very readily). Our pipeline forbids baked-in
     * text — the quote is stamped programmatically by BrandImageStamper after
     * generation — so a model that "helpfully" writes the headline into the
     * scene corrupts the asset. Returns the reinforcement clause for those
     * models and '' for flux-family models (which don't need it), so the
     * prompt isn't needlessly inflated.
     */
    public static function noTextReinforcementFor(string $model): string
    {
        if (! FalAiClient::modelUsesAspectRatio($model)) {
            return '';
        }

        return 'CRITICAL — this model can render text but MUST NOT here: produce a pure photographic scene with zero text of any kind. '
            .'Do not write the caption, headline, quote, brand name, or any words into the image. '
            .'If the brief mentions a phrase, depict its MEANING as a scene, never the letters. Any text in the output is a defect.';
    }

    /**
     * True when a draft should render as a SUMMARY POSTER (designed graphic
     * with a headline + key points as legible text) rather than a text-free
     * editorial photo. Per the per-format decision: poster for single-image
     * educational / listicle / quote-card / infographic intent; photo for
     * everything else (lifestyle, brand-moment, reel/video keyframes).
     *
     * Gate signals (all read from the calendar entry, no new columns):
     *   - format must be 'single_image' (carousel has its own slide path;
     *     reel/video are photo-anchored keyframes).
     *   - AND either pillar === 'educational', or visual_direction names a
     *     poster kind (infographic / listicle / tips / steps / quote card /
     *     summary / checklist / how-to).
     *
     * Poster rendering only makes sense on a text-capable model — the caller
     * also checks FalAiClient::modelUsesAspectRatio() before choosing poster
     * mode, so flux drafts never attempt baked-in body text.
     */
    public static function isPosterFormat(?string $format, ?string $pillar, ?string $visualDirection): bool
    {
        if (strtolower(trim((string) $format)) !== 'single_image') {
            return false;
        }

        if (strtolower(trim((string) $pillar)) === 'educational') {
            return true;
        }

        $vd = strtolower((string) $visualDirection);
        foreach (['infographic', 'listicle', 'list post', 'tips', 'steps', 'step-by-step', 'quote card', 'quote-card', 'summary', 'checklist', 'how-to', 'how to', 'breakdown'] as $needle) {
            if (str_contains($vd, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Layout brief for a Nano-Banana-rendered summary poster. Unlike the photo
     * path this DELIBERATELY asks the model to render legible text (the title +
     * points), so it must NOT be combined with the no-text clauses. The poster
     * content (title + points) is distilled by PosterContentWriter and injected
     * by the caller via {@see self::posterContentBlock()}.
     */
    public static function posterDirective(): string
    {
        return implode(' ', [
            'Design a clean, modern social-media SUMMARY POSTER (an editorial infographic-style graphic, not a photograph).',
            'Render the provided title as a bold, large headline at the top, and the provided key points as a vertically stacked list below it — each point on its own line, clearly legible, high contrast against the background.',
            'Spelling must be EXACT — render the words exactly as given, no extra words, no invented text, no lorem ipsum, no decorative gibberish characters.',
            'Use generous spacing, a strong typographic hierarchy (headline largest, points secondary), and a simple flat or softly-textured background that keeps the text readable on a phone screen.',
            'Premium editorial design — think a well-set magazine explainer card — not clip-art, not a meme, not a busy collage. Leave safe-zone margins so no text is cropped.',
        ]);
    }

    /**
     * The exact title + points the poster must render, formatted so the model
     * treats them as literal copy to typeset (not as a scene to depict).
     *
     * @param  array<int,string>  $points
     */
    public static function posterContentBlock(string $title, array $points): string
    {
        $clean = array_values(array_filter(array_map(
            static fn ($p) => trim((string) $p),
            $points,
        ), static fn ($p) => $p !== ''));

        $lines = '';
        foreach ($clean as $i => $point) {
            $lines .= sprintf(' %d) %s.', $i + 1, $point);
        }

        return sprintf(
            'POSTER TEXT TO RENDER EXACTLY — headline: "%s".%s Render only these words, spelled exactly, nothing else.',
            trim($title),
            $lines,
        );
    }

    /**
     * True when a draft should render as a MULTI-PANEL INFOGRAPHIC POSTER
     * (title bar → grid of labelled panels, each with a small illustration +
     * a few bullets → footer takeaway) rather than the simpler single-headline
     * poster. This is the dense "explainer card" look.
     *
     * Fires for:
     *   - any 'carousel' format (its slides ARE the panels — one panel per
     *     slide), OR
     *   - a 'single_image' poster format (isPosterFormat) that has enough
     *     panels to warrant a grid (>= 3, decided by the caller from the
     *     distilled panel count).
     *
     * Carousel is the primary trigger: a carousel post is inherently a
     * multi-section explainer, so a single dense infographic represents it far
     * better than one photo of the first slide.
     */
    public static function isInfographicFormat(?string $format, ?string $pillar, ?string $visualDirection): bool
    {
        $f = strtolower(trim((string) $format));
        if ($f === 'carousel') {
            return true;
        }

        // single_image poster formats can also become an infographic when they
        // carry enough panels — the caller gates on the distilled panel count.
        return self::isPosterFormat($format, $pillar, $visualDirection);
    }

    /**
     * Layout brief for a Nano-Banana-rendered MULTI-PANEL INFOGRAPHIC. Mirrors
     * the target reference: a strong title bar across the top, an even grid of
     * labelled panels (each with a heading, a small relevant illustration, and
     * 2-3 short bullet points), and a single-line takeaway footer. Deliberately
     * asks the model to render legible text, so it must NOT be combined with
     * the no-text clauses.
     *
     * @param  int  $panelCount  drives the grid hint (2x2 for 4, etc.)
     */
    public static function infographicDirective(int $panelCount): string
    {
        $grid = match (true) {
            $panelCount <= 2 => 'two side-by-side panels',
            $panelCount === 3 => 'three panels in a row or an even arrangement',
            $panelCount === 4 => 'a clean 2x2 grid of four equal panels',
            $panelCount <= 6 => 'an even grid of '.$panelCount.' panels (2 columns)',
            default => 'an even multi-column grid of panels',
        };

        return implode(' ', [
            'Design a premium, modern SOCIAL-MEDIA INFOGRAPHIC POSTER (a designed explainer graphic, NOT a photograph, NOT a single illustration).',
            'Layout, top to bottom: (1) a bold title bar across the very top with the headline; (2) '.$grid.', each panel clearly separated with its own rounded card, a short panel heading, a small simple relevant icon or mini-illustration, and 2-3 short bullet points; (3) a single-line takeaway banner across the bottom.',
            'Spelling must be EXACT — render every heading, bullet and the footer exactly as provided, in the same order. No invented words, no lorem ipsum, no gibberish/decorative fake text inside the panels or illustrations.',
            'Strong typographic hierarchy: title largest, panel headings medium, bullets smaller but still crisp and legible on a phone. Generous padding, clear separation between panels, consistent alignment.',
            'Editorial/business-explainer aesthetic — flat vector style with simple icons, cohesive limited palette, high contrast text. Not clip-art chaos, not a meme, not a photo collage. Leave safe-zone margins so no text is cropped.',
        ]);
    }

    /**
     * The exact infographic copy to typeset: a title, an ordered list of panels
     * (heading + bullets + optional illustration hint), and a footer takeaway.
     * Formatted so the model treats it as literal copy + layout, not a scene.
     *
     * @param  array<int,array{heading:string,bullets:array<int,string>,illustration?:string}>  $panels
     */
    public static function infographicContentBlock(string $title, array $panels, string $footer = ''): string
    {
        $out = sprintf('INFOGRAPHIC TEXT TO RENDER EXACTLY. Title bar: "%s".', trim($title));

        foreach (array_values($panels) as $i => $panel) {
            $heading = trim((string) ($panel['heading'] ?? ''));
            $bullets = array_values(array_filter(array_map(
                static fn ($b) => trim((string) $b),
                is_array($panel['bullets'] ?? null) ? $panel['bullets'] : [],
            ), static fn ($b) => $b !== ''));

            $bulletStr = $bullets === '' ? '' : implode('; ', $bullets);
            $illustration = trim((string) ($panel['illustration'] ?? ''));
            $illoStr = $illustration !== '' ? sprintf(' [small illustration: %s]', $illustration) : '';

            $out .= sprintf(
                ' Panel %d heading: "%s"; bullets: %s.%s',
                $i + 1,
                $heading,
                $bulletStr !== '' ? $bulletStr : '(none)',
                $illoStr,
            );
        }

        if (trim($footer) !== '') {
            $out .= sprintf(' Footer takeaway banner: "%s".', trim($footer));
        }

        $out .= ' Render only these words, spelled exactly, in this order. The illustrations are picture hints only — do NOT print the bracketed illustration text.';

        return $out;
    }

    /**
     * Positive kinetic + camera-motion clauses for Wan 2.6 short-form video.
     * Covers, per the FAL Wan optimisation rules: motion dynamics, camera
     * movement, pacing, believable physics, real lighting in motion, and
     * natural human movement — plus the in-prompt "avoid" list. Phrased
     * 9:16-native by default (the agent still passes aspect_ratio explicitly).
     */
    public static function videoRealismBlock(): string
    {
        return implode(' ', [
            // Kinetic execution — what the camera and subject DO.
            'Camera motion is real and motivated — a slow cinematic dolly-in, a gentle parallax pan, or a subtle handheld tracking shot — never a frantic AI swirl or a robotic perfectly-linear glide.',
            'Subject movement is natural and physically believable: weight, momentum and follow-through read correctly; cloth, hair and environment respond to motion as in real footage.',
            'Lighting behaves like real light across the shot — soft natural daylight or practical sources, with shadows that move plausibly; colour grading stays editorial and true-to-life, not over-processed.',
            'Pacing is deliberate and resolves on a composed final beat — never rapid 0.3-second jump cuts, never strobing, never a frenetic cut-to-black.',
            'The clip should feel captured on a real camera by a real creator — organic framing, authentic depth, premium but human.',
            'If people appear: natural posture and gait, believable eye-line, correct anatomy and hands throughout the motion, genuine expression — never uncanny, never morphing faces or warping limbs.',
            // In-prompt negative reinforcement (Wan honours the field, but doubling in the positive helps).
            'AVOID: CGI or 3D-render look, plastic skin, morphing or flickering faces, warped hands, unreal HDR glow, stock-video clichés, rapid chaotic cuts, baked-in on-screen text, captions or watermarks.',
        ]);
    }

    /**
     * Structured negative prompt for Wan 2.6 video. Kept under Wan's 500-char
     * limit. Targets temporal artefacts (flicker, morph, warp) on top of the
     * still-image AI tells.
     */
    public static function videoNegativePrompt(): string
    {
        return implode(', ', [
            'CGI', '3D render', 'plastic skin', 'cartoon',
            'morphing face', 'flickering', 'warping limbs', 'distorted hands',
            'extra fingers', 'bad anatomy', 'deformed', 'jitter', 'stutter',
            'unreal cinematic glow', 'neon glow', 'oversaturated', 'over-processed lighting',
            'rapid chaotic cuts', 'strobing', 'stock video cliché',
            'on-screen text', 'caption', 'subtitles', 'watermark', 'logo', 'AI artifacts',
        ]);
    }
}
