<?php

namespace App\Services\Imagery;

/**
 * Canonical creative-direction contract for AI still-image generation.
 *
 * Encodes the realism + anti-AI-aesthetic rules every DesignerAgent image
 * prompt must carry so generated social creative looks naturally photographed,
 * platform-native, and publishable with minimal editing — instead of the
 * glossy, plastic, over-cinematic "obvious AI" look that tanks engagement.
 *
 * Two surfaces:
 *   - realismBlock()  → positive clauses appended to the prompt. These are
 *     the only steering signal that works on the default model
 *     (fal-ai/flux-pro/v1.1, which has NO negative_prompt field — verified
 *     against FAL's schema 2026-05-28). The "avoid …" clauses here are the
 *     in-prompt equivalent of a negative prompt for that model.
 *   - negativePrompt() → a structured negative string passed to
 *     FalAiClient::generateImage. flux-pro/v1.1 silently ignores it, but
 *     models a workspace may flip to (flux/dev, recraft-v3, SD-family) honour
 *     it, so we send it rather than throw the signal away.
 *
 * Single source of truth: both the EIAAW house-brand path and the client
 * path in DesignerAgent::buildPrompt() consume these, so the realism contract
 * never drifts between the two.
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
}
