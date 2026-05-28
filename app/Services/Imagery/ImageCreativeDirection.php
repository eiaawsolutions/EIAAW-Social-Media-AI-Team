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
