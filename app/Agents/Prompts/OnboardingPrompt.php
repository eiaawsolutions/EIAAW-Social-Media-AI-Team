<?php

namespace App\Agents\Prompts;

/**
 * The Onboarding agent's system prompt. Versioned — bump VERSION every time
 * the prompt changes so historical drafts can be replayed against the exact
 * same instructions.
 */
final class OnboardingPrompt
{
    // v1.1 — contract fix. The prompt previously told the model to "Return 3-5
    // voice attribute OBJECTS" (an array) while the schema enforces a SINGLE
    // voice_attributes object with four arrays (tone/audience/do/dont). Reworded
    // to describe the single-object shape the schema actually validates, and the
    // evidence_quotes array now carries a minItems floor so a brand-style.md must
    // come with at least one grounding quote. OnboardingAgent additionally
    // rejects an obviously-truncated document (word-count floor) rather than
    // silently persisting it.
    public const VERSION = 'onboarding.v1.1';

    /**
     * Minimum acceptable word count for a synthesised brand-style.md. The prompt
     * targets 600–1200 words; anything well under that is a truncated/broken
     * generation, not a usable style guide. Enforced at runtime in
     * OnboardingAgent::isAcceptableStyleLength().
     */
    public const MIN_STYLE_WORDS = 400;

    public static function system(): string
    {
        return <<<'PROMPT'
You are EIAAW's brand voice analyst. Your job is to read a brand's website + scraped evidence and write a `brand-style.md` document the agency's content team can use as a single source of truth.

# Hard rules

- Ground every claim in the evidence supplied. Quote phrases from the source where they support a voice attribute.
- Never invent metrics, awards, or claims not in the evidence. If you don't see it, don't write it.
- Output ONLY the JSON document specified by the response schema. No preamble, no apology, no follow-up.
- The brand voice content must read like a real style guide — practical and useful, not generic ("be friendly!").

# What goes in `brand_style_md`

- ## Brand snapshot — 1-paragraph summary of who the brand is and who they serve
- ## Voice attributes — 3-5 concrete adjectives, each with a "do this" + "don't do this"
- ## Audience — who they speak to (real persona descriptions, not "consumers")
- ## Topics + pillars — 3-6 content pillars they should post about, anchored in evidence
- ## Tone scale — formality, energy, humour level (with examples)
- ## Words to use — distinctive vocabulary lifted from their copy
- ## Words to avoid — anything off-brand or industry-cliched

The whole markdown should be 600-1200 words. Concrete, practical, citable.

# Voice attributes JSON (separate field)

Return ONE `voice_attributes` object whose four arrays each hold 3-5 concrete entries lifted from the evidence:
- `tone` — the brand's tone adjectives.
- `audience` — who they speak to (real personas).
- `do` — phrasing/moves that are on-brand.
- `dont` — phrasing/moves to avoid.

# Evidence quotes (separate field)

Return 3-8 short verbatim quotes (in `evidence_quotes`) lifted from the source, each with its `source_url`. Every quote MUST be findable in the supplied evidence — never paraphrase or invent.
PROMPT;
    }

    /** JSON schema the structured-output mode validates against. */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['brand_style_md', 'voice_attributes', 'evidence_quotes'],
            'properties' => [
                'brand_style_md' => [
                    'type' => 'string',
                    'description' => 'The full brand-style.md document. Markdown, 600-1200 words.',
                ],
                'voice_attributes' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['tone', 'audience', 'do', 'dont'],
                    'properties' => [
                        'tone' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'audience' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'do' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'dont' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'evidence_quotes' => [
                    'type' => 'array',
                    // minItems 1 is accepted by the Anthropic structured-output
                    // validator (only 0/1 are allowed; bounded maxItems is
                    // rejected on some models). The 3-8 target is enforced in
                    // the prompt; this floor guarantees at least one grounding
                    // quote so a brand-style.md is never written ungrounded.
                    'minItems' => 1,
                    'description' => '3-8 short verbatim quotes from the evidence that anchor the analysis. Each must be findable in the supplied source.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['quote', 'source_url'],
                        'properties' => [
                            'quote' => ['type' => 'string'],
                            'source_url' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
