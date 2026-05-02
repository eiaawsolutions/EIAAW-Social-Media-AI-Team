<?php

namespace App\Agents\Prompts;

final class StrategistPrompt
{
    public const VERSION = 'strategist.v1.0';

    public static function system(): string
    {
        return <<<'PROMPT'
You are EIAAW's content strategist. Your job is to plan a brand's content month: 30 calendar entries (one per day) with the right pillar mix, format mix, and platform distribution to drive measurable outcomes.

# Hard rules

- Plan a FULL MONTH: target 30 entries (one per day, day_offset 0 through 29). Returning fewer than 20 entries is a failure of the task.
- Every entry must align with the supplied brand-style. Don't invent off-brand topics.
- Distribute across content pillars and formats per the supplied mix percentages.
- Spread platform targets evenly so no single platform is starved.
- Topics must be specific enough that the Writer agent can produce a real caption from them. "Talk about culture" is not a topic. "Behind-the-scenes: how our 3-person SDR team books 40 demos a week" is.
- Avoid duplicate topics within the month.
- Output ONLY the JSON document specified. No commentary.

# How to design the calendar

1. Translate the brand pillars into 30 diverse, specific angles.
2. Tag each with pillar + format + platforms.
3. For each format, default visual_direction to a one-sentence brief the Designer can act on.
4. Stagger high-effort posts (reels, carousels) across the month — don't bunch them.
5. Schedule typically Mon-Fri; weekends only if the brand is consumer-facing.
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['period_label', 'entries'],
            'properties' => [
                'period_label' => [
                    'type' => 'string',
                    'description' => 'Human readable e.g. "May 2026"',
                ],
                'entries' => [
                    'type' => 'array',
                    // Anthropic's structured-output validator only allows
                    // minItems values of 0 or 1 (and rejects bounded maxItems
                    // on some models). Range enforcement lives in the system
                    // prompt ("plan a 30-entry month") and is post-validated
                    // in StrategistAgent::handle() after the call returns.
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['day_offset', 'topic', 'angle', 'pillar', 'format', 'platforms', 'objective', 'visual_direction'],
                        'properties' => [
                            'day_offset' => [
                                'type' => 'integer',
                                // Anthropic's structured-output validator
                                // rejects minimum/maximum on integer types.
                                // Range (0–30) is enforced via the prompt and
                                // clamped in StrategistAgent before insert.
                                'description' => 'Days from period_starts_on, integer 0–30 (0 = first day).',
                            ],
                            'topic' => ['type' => 'string'],
                            'angle' => ['type' => 'string', 'description' => 'The hook / specific take.'],
                            'pillar' => [
                                'type' => 'string',
                                'enum' => ['educational', 'community', 'promotional', 'behind_the_scenes', 'thought_leadership'],
                            ],
                            'format' => [
                                'type' => 'string',
                                'enum' => ['single_image', 'carousel', 'reel', 'text_only', 'video', 'story'],
                            ],
                            'platforms' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'string',
                                    'enum' => ['instagram', 'facebook', 'linkedin', 'tiktok', 'threads', 'x', 'youtube', 'pinterest'],
                                ],
                                'minItems' => 1,
                            ],
                            'objective' => [
                                'type' => 'string',
                                'enum' => ['awareness', 'engagement', 'traffic', 'leads', 'retention'],
                            ],
                            'visual_direction' => [
                                'type' => 'string',
                                'description' => 'One sentence brief for the Designer agent.',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
