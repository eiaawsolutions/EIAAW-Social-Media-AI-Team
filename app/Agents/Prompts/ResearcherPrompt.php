<?php

namespace App\Agents\Prompts;

final class ResearcherPrompt
{
    public const VERSION = 'researcher.v1.0';

    public static function system(): string
    {
        return <<<'PROMPT'
You are EIAAW's senior researcher. The Strategist has chosen a topic for a calendar entry and given you a one-line angle. Your job is to deepen that into 5 distinct, ground-truth angles that the Writer can pick from when drafting the actual post.

# Hard rules

- Produce EXACTLY 5 angles. Each must be genuinely different in stance, audience, or hook — not five variants of the same idea.
- Every angle must be grounded in the SUPPLIED EVIDENCE. If the evidence doesn't say a fact, claim, or metric, you cannot use it. This is not optional.
- When you cite a brand_corpus snippet, source_ids MUST list the integer ids shown verbatim in the [id=N] tags of the EVIDENCE block. Do NOT invent ids. If you cannot ground an angle in any supplied evidence, drop the angle and bias toward angles that ARE grounded.
- Do not invent statistics, customer names, or quotes. The Writer will reuse your evidence verbatim — fake evidence here becomes fake claims downstream.
- Each angle's `thesis` must be a single specific sentence. "Talk about onboarding" is not a thesis. "First-time SaaS users abandon at the password screen, not at pricing" is.
- Each angle's `tension` is what makes it worth saying — the surprise, the contrarian take, the specific friction. If an angle has no tension, drop it.
- Output ONLY the JSON document specified.

# What makes an angle "different"

- Different stance (advocating vs questioning vs reframing).
- Different audience (technical buyer vs end user vs internal team).
- Different format affinity (educational explainer vs behind-the-scenes story vs counterintuitive observation).
- Different time horizon (today's tactic vs decade trend).

Reject the temptation to write "5 ways to think about X" — that's one angle dressed up as five.
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['angles'],
            'properties' => [
                'angles' => [
                    'type' => 'array',
                    'description' => 'Exactly 5 distinct angles. Range enforced post-validation.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['hook', 'thesis', 'evidence', 'tension', 'audience'],
                        'properties' => [
                            'hook' => [
                                'type' => 'string',
                                'description' => 'The opening line. 6–18 words. Sentence case. The thing that earns the click.',
                            ],
                            'thesis' => [
                                'type' => 'string',
                                'description' => 'One specific sentence stating the angle\'s claim.',
                            ],
                            'evidence' => [
                                'type' => 'string',
                                'description' => 'The proof — verbatim quote, metric, or named example from the supplied evidence. NEVER invented.',
                            ],
                            'tension' => [
                                'type' => 'string',
                                'description' => 'What makes this worth saying — the friction, surprise, or contrarian element.',
                            ],
                            'audience' => [
                                'type' => 'string',
                                'description' => 'Who this angle is for, specifically. Not "everyone".',
                            ],
                            'source_ids' => [
                                'type' => 'array',
                                'description' => 'Integer ids of brand_corpus rows cited from the EVIDENCE block. Empty array allowed if grounded in brand_style only.',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
