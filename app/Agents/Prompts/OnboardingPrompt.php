<?php

namespace App\Agents\Prompts;

/**
 * The Onboarding agent's system prompt. Versioned — bump VERSION every time
 * the prompt changes so historical drafts can be replayed against the exact
 * same instructions.
 */
final class OnboardingPrompt
{
    public const VERSION = 'onboarding.v1.0';

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

Return 3-5 voice attribute objects with `tone`, `audience`, `do`, `dont` arrays. Use concrete examples lifted from the evidence.
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
