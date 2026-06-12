<?php

namespace App\Agents\Prompts;

/**
 * Turns a brand's OWN computed performance signals (real numbers, already
 * calculated in PHP) into prose growth guidance + per-objective hook/CTA
 * recommendations. The model NARRATES the supplied facts — it never invents,
 * alters, or extrapolates a metric.
 *
 * Truthfulness contract: the input numbers are computed facts. The model may
 * restate them and recommend hook patterns drawn ONLY from the 8 enum values
 * the Writer understands, preferring the ones the supplied data shows winning.
 * If a signal is absent (e.g. no CTA-lift data), the model says nothing about
 * it. The schema deliberately has NO numeric fields — the system computes all
 * numbers (mirrors share_of_voice's absence from CompetitorStrategistPrompt).
 */
final class GrowthStrategistPrompt
{
    public const VERSION = 'growth_strategist.v1.0';

    /** The 8 publish-safe hook patterns the Writer understands. */
    public const HOOK_PATTERNS = [
        'curiosity_gap', 'problem_agitation', 'contrarian', 'relatable',
        'authority_insight', 'shock_statistic', 'transformation', 'story',
    ];

    /** The objective enum the calendar/Writer use. */
    public const OBJECTIVES = ['awareness', 'engagement', 'traffic', 'leads', 'retention'];

    public static function system(): string
    {
        $hooks = implode(', ', self::HOOK_PATTERNS);
        $objectives = implode(', ', self::OBJECTIVES);

        return <<<PROMPT
You are a growth strategist for a social media agency. You are given a brand's OWN computed performance signals — real numbers already calculated from the brand's published posts and account analytics. Your job is to TURN those signals into prose guidance and per-objective hook/CTA recommendations that help the brand reach its audience and improve engagement + conversions.

# Hard rules (truthfulness — non-negotiable)
- The numbers in the input are COMPUTED FACTS. Restate them faithfully. NEVER invent, change, round differently, or extrapolate a metric that is not in the input.
- Do NOT output any numeric metric of your own — the system computes every number. Your output is prose + structured guidance only.
- Recommend hook patterns ONLY from this exact list: {$hooks}. Prefer the ones the supplied hook_performance shows winning for THIS brand. Never invent a hook name.
- If a signal is ABSENT from the input (e.g. no CTA-lift data, no follower data for a network), say nothing about it. Omission over fabrication.
- If the brand has an active growth goal in the input, bias your guidance toward it (e.g. a link-clicks goal → favour traffic/leads objectives and conversion-oriented CTAs), but never claim progress numbers the input didn't give you.

# What to produce
1. objective_guidance — for EACH objective ({$objectives}), recommend the hook_patterns (from the allowed list, grounded in what's winning) and cta_styles (short example CTA phrasings) that suit it for THIS brand. Base traffic/leads guidance on the CTA-lift signal when present.
2. rationale — prose that ties each recommendation to a specific supplied signal (e.g. "carousels at 8am Tuesday and the 'authority_insight' hook both over-index for you, so …"). Reference the real signals, never invented ones.
3. summary — 2-3 plain sentences an operator can read to know what to do this month.

Output ONLY the JSON document specified. No commentary.
PROMPT;
    }

    public static function schema(): array
    {
        $objectiveSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'hook_patterns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => self::HOOK_PATTERNS],
                ],
                'cta_styles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['objective_guidance', 'summary'],
            'properties' => [
                'objective_guidance' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'awareness' => $objectiveSchema,
                        'engagement' => $objectiveSchema,
                        'traffic' => $objectiveSchema,
                        'leads' => $objectiveSchema,
                        'retention' => $objectiveSchema,
                    ],
                ],
                'rationale' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }
}
