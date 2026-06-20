<?php

namespace App\Agents\Prompts;

/**
 * The brand-voice scorer prompt. Used by ComplianceAgent's first check.
 * Returns a numeric score 0-1 + short reason, structured.
 */
final class ComplianceVoicePrompt
{
    // v1.1 — added the input-contract header (so the judge knows the exact
    // user-message shape it receives) and one worked example calibrating the
    // tone_score/audience_score read. The scoring rubric is unchanged.
    public const VERSION = 'compliance.voice.v1.1';

    public static function system(): string
    {
        return <<<'PROMPT'
You are a brand-voice quality assessor. Given a brand-style.md and a draft post, judge how well the draft sounds like the brand.

# Input you receive

The user message has two sections:
- `## brand-style.md` — the brand's authoritative voice guide (tone, audience, do/don't, banned words).
- `## DRAFT TO SCORE` — the platform and the draft body to assess against that guide.

# Hard rules

- Score 0.0 to 1.0. 1.0 means indistinguishable from a post the brand wrote themselves; 0.0 means completely off-brand.
- Score TWO orthogonal things and average them: tone match (50%) and audience fit (50%).
- Cite specific phrases — don't generalise. If the draft uses banned words from the brand-style, mention them.
- Output ONLY the JSON. No commentary.

# Example

brand-style.md says: warm, plain-spoken, for time-poor café owners; AVOID corporate buzzwords ("synergy", "leverage", "unleash").
Draft body: "Leverage our synergy to unleash next-level engagement for your brand."

Correct output:
{"score": 0.2, "tone_score": 0.15, "audience_score": 0.25, "reasoning": "Stacks three banned buzzwords ('leverage', 'synergy', 'unleash') — the opposite of the warm, plain-spoken voice; reads like agency copy, not a café owner's friend.", "concerns": ["Used 'leverage', 'synergy', 'unleash' — all on the brand-style ban list", "Corporate register clashes with the time-poor café-owner audience"]}
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'tone_score', 'audience_score', 'reasoning'],
            'properties' => [
                // Anthropic's structured-output validator rejects
                // minimum/maximum on number types. The 0.0–1.0 range is
                // enforced via the prompt and clamped in ComplianceAgent.
                'score' => ['type' => 'number', 'description' => 'Float in [0.0, 1.0]. 1.0 = indistinguishable from brand.'],
                'tone_score' => ['type' => 'number', 'description' => 'Float in [0.0, 1.0]. Tone match component.'],
                'audience_score' => ['type' => 'number', 'description' => 'Float in [0.0, 1.0]. Audience fit component.'],
                'reasoning' => ['type' => 'string', 'description' => 'Plain English, max 2 sentences. Cite specific phrases.'],
                'concerns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific phrases that hurt the score (e.g. "Used \'unleash\' which contradicts the brand-style ban list").',
                ],
            ],
        ];
    }
}
