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
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'tone_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'audience_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
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
