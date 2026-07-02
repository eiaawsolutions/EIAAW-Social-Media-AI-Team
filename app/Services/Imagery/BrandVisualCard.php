<?php

namespace App\Services\Imagery;

use App\Models\Brand;
use App\Models\BrandStyle;
use Illuminate\Support\Str;

/**
 * Per-brand visual-direction card for AI image generation — the CLIENT analogue
 * of {@see EiaawBrandLock}. It renders a brand's own visual identity (palette,
 * mood, imagery style, composition, lighting, forbidden treatments) into the
 * same prompt slot the EIAAW house brand fills with its locked directive, so a
 * client's generated images look like they belong to that brand instead of
 * generic stock.
 *
 * The problem this fixes: {@see \App\Agents\DesignerAgent::buildPrompt()} used to
 * inject, for client brands, only the brand NAME + a hard-coded per-platform
 * aesthetic string + a "Brand palette: {hex}" hint — and that hint was always
 * empty because brand_styles.palette was never populated. The model got almost
 * nothing about the brand, so it produced decorative stock imagery. This card
 * gives every enriched brand the same quality of art direction the house brand
 * gets.
 *
 * Data sources, in priority order (best available wins, per clause):
 *   1. brand_styles.visual_identity — the structured, grounded identity written
 *      by BrandVisualIdentityAgent (Phase 2: vision-extracted from the brand's
 *      own site images, or operator-supplied). Preferred when present.
 *   2. A best-effort synthesis (Phase 1) from data that ALREADY exists today:
 *      brand_styles.palette (if populated), voice_attributes.tone (mood cues),
 *      and the brand industry. This lets un-backfilled brands still beat the old
 *      generic string, without waiting on the vision pipeline.
 *
 * TRUTHFULNESS: this card only renders identity that was extracted from the
 * brand's real assets or entered by the operator — it never invents a palette or
 * a visual fact. When there is genuinely no signal, {@see self::isAvailable()}
 * returns false and DesignerAgent falls back to its exact legacy prompt (zero
 * regression). Mood/adjective synthesis in Phase 1 is derived from the brand's
 * own voice attributes, not fabricated.
 *
 * Stateless + DB-free-testable: {@see self::forStyle()} builds a card from a
 * BrandStyle (or null) + name + industry, so buildPrompt() can be unit-tested
 * with an unsaved Brand and no database.
 */
final class BrandVisualCard
{
    /**
     * Soft cap for the rendered image directive. Nano-banana / Gemini prompts
     * degrade when overly long; the scene brief, realism block and no-text
     * clause also consume budget. When the assembled directive exceeds this we
     * drop the lowest-value clauses first (forbidden list, then extra mood
     * adjectives) — never the palette or imagery, which carry the brand look.
     */
    public const MAX_DIRECTIVE_CHARS = 900;

    /** Max brand-facts characters injected alongside the card (see buildPrompt). */
    public const MAX_FACTS_CHARS = 400;

    private const MAX_FORBIDDEN = 5;

    private const MAX_MOOD = 6;

    /**
     * @param  array<string,mixed>  $identity  normalised visual-identity payload
     */
    private function __construct(
        private readonly string $brandName,
        private readonly string $industry,
        private readonly array $identity,
    ) {}

    /** Resolve from a Brand (reads its current BrandStyle relation). */
    public static function forBrand(Brand $brand): self
    {
        return self::forStyle(
            $brand->currentStyle,
            (string) ($brand->name ?? ''),
            (string) ($brand->industry ?? ''),
        );
    }

    /**
     * Build a card from an explicit style (or null) — the DB-free seam used by
     * both forBrand() and the unit tests.
     */
    public static function forStyle(?BrandStyle $style, string $brandName, string $industry = ''): self
    {
        return new self(
            trim($brandName),
            trim($industry),
            self::resolveIdentity($style),
        );
    }

    /**
     * True when there is enough real signal to render a brand-specific card.
     * Requires a palette OR (a mood + an imagery/composition cue). When false,
     * the caller keeps its exact legacy prompt so an un-enriched brand is
     * byte-identical to the pre-feature behaviour.
     */
    public function isAvailable(): bool
    {
        $hasPalette = $this->paletteClause() !== '';
        $hasMood = $this->moodClause() !== '';
        // Imagery signal = an explicit imagery/composition/subject cue OR the
        // grounded industry cue (which imageryClause() renders as a fallback).
        $hasImagery = trim((string) ($this->identity['imagery_style'] ?? '')) !== ''
            || trim((string) ($this->identity['composition_rules'] ?? '')) !== ''
            || trim((string) ($this->identity['subject_guidance'] ?? '')) !== ''
            || $this->industryImageryCue() !== '';

        return $hasPalette || ($hasMood && $hasImagery);
    }

    /**
     * Art-direction fragment for still-image generation — same SHAPE as
     * {@see EiaawBrandLock::imageDirective()} but rendered from THIS brand's
     * identity: mood → palette → imagery/subject → composition → lighting →
     * saturation/grain → forbidden. Empty clauses are skipped. Length-guarded.
     */
    public function imageDirective(): string
    {
        $lead = $this->brandName !== ''
            ? sprintf('%s house style:', $this->brandName)
            : 'Brand house style:';

        // Ordered by brand-look value — the length guard trims from the TAIL of
        // this list (forbidden first, then mood) so palette + imagery survive.
        $clauses = array_values(array_filter([
            $this->moodClause(),
            $this->paletteClause(),
            $this->imageryClause(),
            $this->compositionClause(),
            $this->lightingClause(),
            $this->finishClause(),
            $this->forbiddenClause(),
        ], static fn (string $c) => $c !== ''));

        if ($clauses === []) {
            return '';
        }

        return $this->fitToBudget($lead, $clauses);
    }

    /**
     * Brand-style clause for the poster/infographic composer background — palette
     * + mood so the designed graphic stays on-brand. Parallel to the hex-only
     * string DesignerAgent::posterBrandStyle() falls back to.
     */
    public function posterStyleClause(): string
    {
        $parts = array_values(array_filter([
            $this->paletteClause(),
            $this->moodClause(),
        ], static fn (string $c) => $c !== ''));

        return $parts === [] ? '' : 'Brand style for the poster: '.implode(' ', $parts);
    }

    /** Typography feel for incidental in-scene signage (may be empty). */
    public function typographyHint(): string
    {
        $feel = trim((string) ($this->identity['typography_feel'] ?? ''));
        if ($feel === '') {
            return '';
        }

        return 'Any incidental typography reads as '.rtrim($feel, '.').'.';
    }

    /**
     * The brand's primary accent hex (6 chars, no #) for the composer's title
     * bar / footer, or null when no valid palette colour exists.
     */
    public function accentHex(): ?string
    {
        foreach ($this->paletteEntries() as $entry) {
            $hex = self::normaliseHex($entry['hex'] ?? null);
            if ($hex !== null) {
                return $hex;
            }
        }

        return null;
    }

    // ---- clause builders -------------------------------------------------

    private function moodClause(): string
    {
        $mood = $this->moodAdjectives();
        if ($mood === []) {
            return '';
        }

        return 'Overall mood is '.self::humanList($mood).'.';
    }

    private function paletteClause(): string
    {
        $rendered = [];
        foreach ($this->paletteEntries() as $entry) {
            $hex = self::normaliseHex($entry['hex'] ?? null);
            if ($hex === null) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $role = trim((string) ($entry['role'] ?? ''));
            $label = '#'.$hex;
            if ($name !== '') {
                $label = $name.' #'.$hex;
            }
            if ($role !== '') {
                $label .= ' ('.$role.')';
            }
            $rendered[] = $label;
            if (count($rendered) >= 6) {
                break;
            }
        }

        if ($rendered === []) {
            return '';
        }

        return 'Use the brand palette strictly: '.implode(', ', $rendered).'.';
    }

    private function imageryClause(): string
    {
        $style = trim((string) ($this->identity['imagery_style'] ?? ''));
        $subject = trim((string) ($this->identity['subject_guidance'] ?? ''));
        $parts = array_filter([$style, $subject], static fn (string $s) => $s !== '');
        if ($parts === []) {
            // Phase-1 industry fallback so even a palette-only brand gets a
            // subject cue instead of generic stock.
            $industryCue = $this->industryImageryCue();

            return $industryCue === '' ? '' : 'Imagery: '.$industryCue.'.';
        }

        return 'Imagery: '.rtrim(implode('. ', $parts), '.').'.';
    }

    private function compositionClause(): string
    {
        $composition = trim((string) ($this->identity['composition_rules'] ?? ''));

        return $composition === '' ? '' : 'Composition: '.rtrim($composition, '.').'.';
    }

    private function lightingClause(): string
    {
        $lighting = trim((string) ($this->identity['lighting'] ?? ''));

        return $lighting === '' ? '' : 'Lighting: '.rtrim($lighting, '.').'.';
    }

    private function finishClause(): string
    {
        $sat = trim((string) ($this->identity['saturation'] ?? ''));
        $grain = trim((string) ($this->identity['grain'] ?? ''));
        $parts = array_filter([$sat, $grain], static fn (string $s) => $s !== '');

        return $parts === [] ? '' : 'Finish: '.rtrim(implode('; ', $parts), '.').'.';
    }

    private function forbiddenClause(): string
    {
        $forbidden = $this->forbiddenList();
        if ($forbidden === []) {
            return '';
        }

        return 'Forbidden: '.self::humanList($forbidden).'.';
    }

    // ---- identity resolution --------------------------------------------

    /**
     * Normalise the best-available identity into a flat array the clause
     * builders read. Structured visual_identity wins; otherwise synthesise from
     * palette + voice_attributes (Phase 1).
     *
     * @return array<string,mixed>
     */
    private static function resolveIdentity(?BrandStyle $style): array
    {
        if ($style === null) {
            return [];
        }

        $vi = is_array($style->visual_identity ?? null) ? $style->visual_identity : [];

        // Palette: prefer visual_identity.palette (structured hex+name+role),
        // else the legacy brand_styles.palette column (hex strings / {hex} rows).
        $palette = self::coercePalette($vi['palette'] ?? null);
        if ($palette === []) {
            $palette = self::coercePalette($style->palette ?? null);
        }

        // Mood: visual_identity.mood_adjectives, else voice_attributes.tone as a
        // grounded Phase-1 proxy (the brand's own declared tone).
        $mood = self::coerceStringList($vi['mood_adjectives'] ?? null);
        if ($mood === []) {
            $voice = is_array($style->voice_attributes ?? null) ? $style->voice_attributes : [];
            $mood = self::coerceStringList($voice['tone'] ?? null);
        }

        return [
            'palette' => $palette,
            'mood_adjectives' => $mood,
            'imagery_style' => (string) ($vi['imagery_style'] ?? ''),
            'subject_guidance' => (string) ($vi['subject_guidance'] ?? ''),
            'composition_rules' => (string) ($vi['composition_rules'] ?? ''),
            'lighting' => (string) ($vi['lighting'] ?? ''),
            'saturation' => (string) ($vi['saturation'] ?? ''),
            'grain' => (string) ($vi['grain'] ?? ''),
            'typography_feel' => (string) ($vi['typography_feel'] ?? ''),
            'forbidden_visuals' => self::coerceStringList($vi['forbidden_visuals'] ?? null),
        ];
    }

    /**
     * @return array<int,array{hex:?string,name:string,role:string}>
     */
    private function paletteEntries(): array
    {
        return is_array($this->identity['palette'] ?? null) ? $this->identity['palette'] : [];
    }

    /** @return array<int,string> */
    private function moodAdjectives(): array
    {
        $mood = is_array($this->identity['mood_adjectives'] ?? null) ? $this->identity['mood_adjectives'] : [];

        return array_slice($mood, 0, self::MAX_MOOD);
    }

    /** @return array<int,string> */
    private function forbiddenList(): array
    {
        $forbidden = is_array($this->identity['forbidden_visuals'] ?? null) ? $this->identity['forbidden_visuals'] : [];

        return array_slice($forbidden, 0, self::MAX_FORBIDDEN);
    }

    /**
     * Industry-anchored subject cue so a palette-only brand still gets a
     * grounded imagery direction rather than "generic editorial photo". Kept
     * conservative and non-fabricating — a category of subject, not a claim.
     */
    private function industryImageryCue(): string
    {
        $key = Str::of($this->industry)->lower()->toString();

        return match (true) {
            $key === '' => '',
            str_contains($key, 'food') || str_contains($key, 'cafe') || str_contains($key, 'restaurant') || str_contains($key, 'beverage')
                => 'real food, drinks and hands in an authentic service moment, natural textures',
            str_contains($key, 'fashion') || str_contains($key, 'apparel') || str_contains($key, 'beauty')
                => 'the product worn or in use, real materials and skin, editorial styling',
            str_contains($key, 'saas') || str_contains($key, 'software') || str_contains($key, 'tech') || str_contains($key, 'b2b')
                => 'real people at work in believable settings, hands and devices, no sterile 3D product renders',
            str_contains($key, 'health') || str_contains($key, 'clinic') || str_contains($key, 'wellness') || str_contains($key, 'fitness')
                => 'authentic human moments, natural light, calm and trustworthy, real bodies not idealised stock',
            str_contains($key, 'real estate') || str_contains($key, 'property') || str_contains($key, 'construction')
                => 'real spaces and materials, architectural light, human scale',
            str_contains($key, 'finance') || str_contains($key, 'legal') || str_contains($key, 'insurance')
                => 'grounded professional settings and real objects, restrained and credible, no cliché handshakes',
            default => 'real subjects and materials relevant to the brand, documentary-authentic, never generic stock',
        };
    }

    // ---- length budgeting ------------------------------------------------

    /**
     * Assemble the lead + clauses; if over budget, drop clauses from the tail
     * (forbidden, then mood) until it fits. Palette + imagery are ordered ahead
     * of those, so they are the last to go.
     *
     * @param  array<int,string>  $clauses
     */
    private function fitToBudget(string $lead, array $clauses): string
    {
        while ($clauses !== []) {
            $rendered = $lead.' '.implode(' ', $clauses);
            if (mb_strlen($rendered) <= self::MAX_DIRECTIVE_CHARS || count($clauses) === 1) {
                return $rendered;
            }
            array_pop($clauses); // drop lowest-value trailing clause and retry
        }

        return $lead;
    }

    // ---- coercion helpers ------------------------------------------------

    /**
     * Normalise palette input (array of hex strings, or of {hex,name,role}
     * rows) into a consistent shape. Invalid hexes are dropped by the renderer.
     *
     * @return array<int,array{hex:?string,name:string,role:string}>
     */
    private static function coercePalette(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $out[] = ['hex' => self::normaliseHex($entry), 'name' => '', 'role' => ''];

                continue;
            }
            if (is_array($entry)) {
                $out[] = [
                    'hex' => self::normaliseHex($entry['hex'] ?? null),
                    'name' => trim((string) ($entry['name'] ?? '')),
                    'role' => trim((string) ($entry['role'] ?? '')),
                ];
            }
        }

        return array_values(array_filter($out, static fn (array $e) => $e['hex'] !== null));
    }

    /** @return array<int,string> */
    private static function coerceStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v) => trim((string) (is_string($v) ? $v : ($v['label'] ?? $v['value'] ?? ''))),
            $raw,
        ), static fn (string $s) => $s !== ''));
    }

    /** A valid 6-hex colour (uppercased, no #), or null. */
    private static function normaliseHex(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $hex = ltrim(trim($raw), '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : null;
    }

    /**
     * Join a list with commas + a trailing "and" ("a, b and c").
     *
     * @param  array<int,string>  $items
     */
    private static function humanList(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn (string $s) => $s !== ''));
        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
