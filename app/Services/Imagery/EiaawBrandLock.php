<?php

namespace App\Services\Imagery;

use App\Models\Brand;

/**
 * EIAAW Brand Lock — canonical art-direction fragment for image/video prompts
 * when the brand belongs to an `eiaaw_internal` workspace (i.e. EIAAW is
 * posting on its own behalf, not on a client's behalf).
 *
 * Source of truth: ~/.claude/skills/full-stack-engineer/references/eiaaw-design-system.md
 * (LOCKED 2026-04-19). Every fragment below maps directly to a clause there.
 *
 * NOT applied to client workspaces — clients have their own BrandStyle and
 * must not be force-fit into EIAAW's visual language.
 */
final class EiaawBrandLock
{
    public static function appliesTo(Brand $brand): bool
    {
        $workspace = $brand->workspace;
        return $workspace !== null && $workspace->plan === 'eiaaw_internal';
    }

    /**
     * Art-direction fragment for still-image generation (FAL flux). Replaces
     * the generic palette/aesthetic hint when the brand is EIAAW.
     */
    public static function imageDirective(): string
    {
        return implode(' ', [
            'EIAAW Solutions house style: warm editorial aesthetic in the spirit of Monocle and Cereal magazine.',
            'Palette is strict warm-cream + deep-teal: backgrounds in cream #FAF7F2 to warm beige #F3EDE0, accents in deep teal #11766A and bright teal #1FA896, typographic ink in near-black #0F1A1D.',
            'No dark navy, no pure black, no neon, no purple, no orange, no multi-gradient backgrounds.',
            'Composition is asymmetric and editorial — never centred hero blobs, never three-column feature card grids.',
            'Imagery features muted warm tones, natural light, real objects, human hands, APAC-leaning subjects when human — never sterile SaaS product shots, never stock-photo poses, never clip-art icons.',
            'Subject sits on cream paper with generous negative space; if a content image appears within the frame, it gets rounded 14–20px corners with a soft layered drop shadow and a 1px inset highlight rim (the floating-elegant treatment).',
            'Slight desaturation: target saturation ~0.85–0.9, contrast ~1.02. Add fine paper grain texture.',
            'Forbidden: dark-navy SaaS palette, radial teal glows, multiple competing gradients, generic AI-slop swirls.',
        ]);
    }

    /**
     * Art-direction fragment for short-form video generation (FAL Wan 2.6).
     * Same brand spine, adapted to motion: easing without overshoot, no
     * bouncy springs, no rapid cuts.
     */
    public static function videoDirective(): string
    {
        return implode(' ', [
            'EIAAW Solutions house style: warm editorial motion in the spirit of Monocle film features.',
            'Palette is strict warm-cream + deep-teal: cream #FAF7F2 / warm beige #F3EDE0 backgrounds, deep teal #11766A and bright teal #1FA896 accents, near-black #0F1A1D ink — never dark navy, never pure black, never neon.',
            'Camera motion is slow editorial drift — gentle dolly-in, parallax pans, racked focus. No bouncy or elastic easing, no aggressive whip-pans, no rapid 0.3s scene cuts, no AI swirl transitions.',
            'Real objects, natural light, human hands at work, APAC-leaning when human is on screen.',
            'Final beat resolves on a still composition with generous negative space and a deep-teal accent — never a frenetic cut-to-black.',
            'Saturation muted (~0.85–0.9), contrast slightly lifted, fine grain present.',
            'Forbidden: dark-navy/SaaS palettes, neon accents, kinetic typography overlays, stock-video clichés (silhouetted-team-clapping, generic-skyline-timelapse, glowing-data-particles).',
        ]);
    }

    /**
     * Typography hint — used when the platform aesthetic includes on-screen
     * text (LinkedIn squares, YouTube thumbnails). Even though we forbid
     * baked-in text, this anchors any incidental signage in the scene.
     */
    public static function typographyHint(): string
    {
        return 'Any incidental typography in the scene reads as Inter (sans-serif) for primary, Instrument Serif italic for editorial accents, JetBrains Mono uppercase for meta labels — never display script, never decorative serifs.';
    }
}
