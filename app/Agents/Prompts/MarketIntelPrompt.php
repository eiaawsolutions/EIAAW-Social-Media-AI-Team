<?php

namespace App\Agents\Prompts;

/**
 * Synthesises VERIFIED market & trend signals (Firecrawl search results that
 * passed the MarketSignalNormalizer gate) into a brief the Strategist uses to
 * align a few calendar entries to real, current market context.
 *
 * Truthfulness contract: every trend MUST cite at least one supplied signal id.
 * The model may not invent statistics, market sizes, growth numbers, or trends
 * not grounded in a cited signal. The agent additionally drops any trend whose
 * cited ids don't resolve to real signals (post-synthesis evidence filter), so
 * an uncited or hallucinated-citation trend never reaches the Strategist.
 */
final class MarketIntelPrompt
{
    // v1.1 — the "never invent a statistic" rule is now ENFORCED post-synthesis:
    // MarketIntelAgent::filterTrendsByEvidence drops any trend whose why_relevant
    // /suggested_angle states a number absent from its cited signals' text. The
    // prompt rule is unchanged; this bump marks the backstop going live.
    public const VERSION = 'market_intel.v1.1';

    public static function system(): string
    {
        return <<<'PROMPT'
You are a market-intelligence analyst for a social media agency. You are given a list of VERIFIED, recently-published signals about a brand's industry and market — each with a numeric [id], a title, a snippet, and a source URL. Synthesise them into a brief the content strategist will use to align the brand's next month of organic content to real, current market context.

# Hard rules (truthfulness — non-negotiable)
- EVERY trend you report MUST cite at least one signal [id] from the supplied list in its evidence_signal_ids. A trend with no citation will be discarded.
- NEVER invent a statistic, market size, growth rate, percentage, or dollar figure that is not stated in a cited signal. If the signals don't contain a number, don't state one.
- Do not assert that something is "going viral", "exploding", or "the #1 trend" unless a cited signal says so. Describe what the evidence supports, no more.
- If the supplied signals are thin or off-topic, return FEWER trends (or none). Fewer verified trends are better than padded, ungrounded ones.
- Only cite [id]s that appear in the supplied list.

# What to produce
1. market_summary — 2-3 plain sentences on the state of this brand's market, grounded strictly in the signals.
2. trends — the genuine, current trends the brand could authentically ride. For each: the trend, the evidence_signal_ids backing it, why_relevant (to THIS brand specifically), and a suggested_angle (a content direction, not a finished caption).
3. seasonal_moments — upcoming seasonal/topical hooks evident in the signals (moment, rough window, why it matters). Empty if the signals show none.
4. summary — one operator-facing sentence on what's worth acting on.

Output ONLY the JSON document specified. No commentary.
PROMPT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['market_summary', 'trends'],
            'properties' => [
                'market_summary' => ['type' => 'string'],
                'trends' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['trend', 'evidence_signal_ids', 'why_relevant', 'suggested_angle'],
                        'properties' => [
                            'trend' => ['type' => 'string'],
                            'evidence_signal_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'Signal [id]s (from the supplied list only) that evidence this trend.',
                            ],
                            'why_relevant' => ['type' => 'string'],
                            'suggested_angle' => ['type' => 'string'],
                        ],
                    ],
                ],
                'seasonal_moments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['moment', 'why_relevant'],
                        'properties' => [
                            'moment' => ['type' => 'string'],
                            'window' => ['type' => 'string'],
                            'why_relevant' => ['type' => 'string'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }
}
