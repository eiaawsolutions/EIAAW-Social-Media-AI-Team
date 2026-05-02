<?php

namespace App\Agents\Prompts;

/**
 * The brand-voice scorer prompt. Used by ComplianceAgent's first check.
 * Returns a numeric score 0-1 + short reason, structured.
 */
final class ComplianceVoicePrompt
{
    public const VERSION = 'compliance.voice.v1.0';

    public static function system(): string
    {
        return <<<'PROMPT'
You are a brand-voice quality assessor. Given a brand-style.md and a draft post, judge how well the draft sounds like the brand.

# Hard rules

- Score 0.0 to 1.0. 1.0 means indistinguishable from a post the brand wrote themselves; 0.0 means completely off-brand.
- Score TWO orthogonal things and average them: tone match (50%) and audience fit (50%).
- Cite specific phrases — don't generalise. If the draft uses banned words from the brand-style, mention them.
- Output ONLY the JSON. No commentary.
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
