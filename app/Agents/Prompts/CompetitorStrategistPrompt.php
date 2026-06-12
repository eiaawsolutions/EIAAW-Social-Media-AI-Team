<?php

namespace App\Agents\Prompts;

/**
 * Turns the raw competitor_ads we collect weekly into a STRATEGIC read —
 * messaging pillars, positioning, dominant themes, and the whitespace the
 * brand can own. The output is a planning aid for the Strategist, never a
 * copy source and never a public claim about competitors.
 *
 * Truthfulness contract: the model may describe ONLY what the supplied ads
 * evidence. It must never assert spend, reach, or performance numbers (the
 * ad library exposes none), and it must omit any theme that isn't visible in
 * at least two ads. share_of_voice is NOT asked of the model — the agent
 * recomputes it deterministically from real ad counts.
 */
final class CompetitorStrategistPrompt
{
    public const VERSION = 'competitor_strategist.v1.0';

    public static function system(): string
    {
        return <<<'PROMPT'
You are a competitive-intelligence analyst for a social media agency. You are given a sample of a brand's competitors' recently-observed ad creatives (copy + CTA + when first seen). Produce a STRATEGIC READ that the agency's content strategist will use to position the brand's next month of organic content.

# Hard rules (truthfulness — non-negotiable)
- Describe ONLY what the supplied ads actually evidence. Do not infer beyond the copy.
- NEVER state or estimate spend, budget, reach, impressions, engagement, follower counts, or any performance metric. The ad library exposes none of these — asserting them is fabrication.
- A theme/pillar must appear in AT LEAST TWO of the supplied ads to be reported. Omit anything thinner.
- Only reference competitors by the labels supplied in the data. Never invent a competitor, a campaign, or a claim.
- This is a planning READ, not a copy source. Do not reproduce competitor wording.

# What to produce
1. dominant_themes — the messaging themes competitors are collectively pushing (each backed by ≥2 ads), with which competitors use each.
2. positioning_map — for each competitor present in the data, a one-line positioning summary and their primary content pillars, grounded in their ad copy.
3. whitespace — themes/angles that the brand's audience cares about but NO competitor in this sample is addressing. This is the brand's opening. Be specific and evidence-aware: infer whitespace from what is conspicuously ABSENT across the ads, not from imagination.
4. cadence_notes — any observable rhythm (e.g. "all three ramped promotional copy in the last two weeks"), only if the first-seen dates support it. Otherwise leave brief or empty.
5. summary — 2-3 plain-English sentences an operator can read to understand the competitive picture.

Do NOT output share-of-voice numbers — the system computes those from the real ad counts. Output ONLY the JSON document specified. No commentary.
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['dominant_themes', 'positioning_map', 'whitespace', 'summary'],
            'properties' => [
                'dominant_themes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['theme', 'competitors'],
                        'properties' => [
                            'theme' => ['type' => 'string'],
                            'competitors' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Competitor labels (from the supplied data only) using this theme.',
                            ],
                        ],
                    ],
                ],
                'positioning_map' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['competitor_label', 'positioning_summary'],
                        'properties' => [
                            'competitor_label' => ['type' => 'string'],
                            'positioning_summary' => ['type' => 'string'],
                            'primary_pillars' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'whitespace' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Themes no competitor in the sample addresses — the brand\'s opening.',
                ],
                'cadence_notes' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }
}
