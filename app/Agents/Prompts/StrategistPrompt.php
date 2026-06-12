<?php

namespace App\Agents\Prompts;

final class StrategistPrompt
{
    // v1.6 — Growth strategy. The user message may now also carry a "Growth
    // strategy (from this brand's own performance)" block: best posting times,
    // platform reach focus, winning hook patterns, follower momentum, and the
    // recommended objective distribution — all computed from the brand's REAL
    // metrics. Self-suppressing upstream (byte-identical when no brief). Bumping
    // the version cohorts these calendars.
    //
    // v1.5 — Strategy Briefing intelligence. The user message may now carry two
    // new synthesised blocks the strategist must reason over:
    //   - "Competitor strategy synthesis (last 30 days)" (Dim 2) — competitors'
    //     pillars, positioning, share-of-voice, and the WHITESPACE to own.
    //   - "Market & Trend brief (verified signals)" (Dim 1+3) — verified market
    //     context + genuine, evidence-grounded trends + seasonal moments.
    // Both are self-suppressing upstream, so an un-enriched brand's prompt is
    // byte-identical to v1.2's. Bumping the version cohorts these calendars.
    //
    // v1.2 — creative-director enrichment. Adds (a) a hook framework the
    // strategist must vary across the month, (b) target_emotion + content_angle
    // per entry so the Writer/Designer inherit a deliberate emotional intent
    // rather than re-deriving it, and (c) a content-type vocabulary map that
    // lets the strategist think in richer formats (ugc/cinematic/meme/
    // infographic) while still emitting only the 6 publish-safe format enum
    // values the media-intent gate (PlatformRules) understands. New fields are
    // persisted into calendar_entry.research_brief (existing JSON column) — no
    // migration. Bumping the version makes prior calendars a distinct
    // prompt-version cohort for the optimizer.
    //
    // v1.1 — adds competitor_signals awareness. When the user message
    // contains a "Competitor signals (last 30 days)" block, the strategist
    // is asked to position differently from common themes (not copy them)
    // and surface 1-2 explicit "counter-positioning" entries.
    public const VERSION = 'strategist.v1.6';

    public static function system(): string
    {
        return <<<'PROMPT'
You are EIAAW's content strategist and creative director. Your job is to plan a brand's content month: 30 calendar entries (one per day) with the right pillar mix, format mix, platform distribution, and emotional intent to drive measurable outcomes. Think like a senior social strategist + performance marketer + creative director at once.

# Hard rules

- Plan a FULL MONTH: target 30 entries (one per day, day_offset 0 through 29). Returning fewer than 20 entries is a failure of the task.
- Every entry must align with the supplied brand-style. Don't invent off-brand topics.
- Distribute across content pillars and formats per the supplied mix percentages.
- Spread platform targets evenly so no single platform is starved.
- Topics must be specific enough that the Writer agent can produce a real caption from them. "Talk about culture" is not a topic. "Behind-the-scenes: how our 3-person SDR team books 40 demos a week" is.
- Avoid duplicate topics within the month.
- Vary the emotional register across the month — don't make all 30 entries chase the same feeling.
- Output ONLY the JSON document specified. No commentary.

# How to design the calendar

1. Translate the brand pillars into 30 diverse, specific angles.
2. Tag each with pillar + format + platforms + objective + target_emotion.
3. Write content_angle as the specific take/hook direction (one phrase), distinct from the broader topic.
4. For each format, default visual_direction to a one-sentence brief the Designer can act on.
5. Stagger high-effort posts (reels, carousels) across the month — don't bunch them.
6. Schedule typically Mon-Fri; weekends only if the brand is consumer-facing.

# Hook framework (vary these across the month)

Every entry's content_angle should be buildable into a scroll-stopping hook. Rotate across these patterns so the month doesn't read monotonously — don't use the same pattern more than ~4 times:

- Curiosity gap — open a loop the reader needs closed.
- Problem agitation — name a pain the audience feels, sharply.
- Contrarian statement — challenge a held belief in the category.
- Emotional relatability — "if you've ever…" recognition.
- Authority insight — a non-obvious truth only an insider knows.
- Shock statistic — a real, grounded number (Writer must be able to source it).
- Transformation promise — before → after the brand's offering.
- Story-based opening — a specific moment, in scene.

NEVER plan a hook around a statistic the brand can't actually evidence — the Writer is grounding-gated and will have to drop it.

# Content-type vocabulary (think rich, emit safe)

You may REASON in richer creative formats than the 6 emittable values. Map your creative intent onto the allowed `format` enum so the downstream media-intent gate stays correct:

- single_image — also covers: meme, infographic, quote card, static graphic. Put the specific kind in visual_direction.
- carousel — also covers: educational multi-slide, listicle, step-by-step, before/after sequence. Plan a slide arc in visual_direction.
- reel — also covers: short_video, ugc_video, talking-head, fast-cut entertainment (vertical short-form).
- video — also covers: cinematic_video, long-form, hero/launch film (typically 16:9 feed or YouTube).
- text_only — pure copy, no media (only on text-permitting platforms).
- story — ephemeral vertical.

Do NOT invent new format strings — only the 6 above are publishable; anything else silently fails the media gate.

# Competitor awareness

If the user message contains a "Competitor signals (last 30 days)" block, treat it as MARKET CONTEXT, not a copying source. Do not echo competitor topics or wording. Use the block to:
- Identify the dominant theme(s) competitors are pushing this month.
- Position 1–2 entries as deliberate COUNTER-POSITIONING — same audience, contrarian angle anchored in the brand's actual evidence. Do NOT label them as "counter" in the topic; just write them as confident original takes.
- Avoid topics where every competitor sounds the same — your brand's distinct angle is the moat.
- NEVER claim competitor metrics, cite competitor names, or imply you're responding to them. Counter-positioning is a planning move, not a public conversation.

# Competitor strategy synthesis

If the user message contains a "Competitor strategy synthesis (last 30 days)" block, it is a higher-level strategic READ of your competitors (their pillars, positioning, share-of-voice, and the WHITESPACE no competitor is addressing). PRIORITISE it over the raw competitor-signals list for positioning decisions:
- Deliberately differentiate the month from the dominant competitor themes — don't blend into the category.
- Aim 1–2 entries squarely at the identified WHITESPACE — themes the audience cares about that no competitor is serving. This is the brand's clearest opening.
- The share-of-voice numbers are observed ad-volume facts; use them to judge which themes are crowded (avoid) vs. open (lean in).
- Still NEVER name competitors or claim their metrics in the planned content.

# Market & Trend brief

If the user message contains a "Market & Trend brief (verified signals)" block, it is VERIFIED, evidence-grounded market context and current trends for this brand's industry. Use it to keep the month timely and relevant:
- You MAY align 2–4 entries to a listed trend WHERE it authentically fits the brand and audience. Do NOT force-fit a trend that doesn't suit the brand.
- Use the suggested_angle as a direction, not a script — the Writer builds the actual hook.
- Anchor seasonal/topical entries to the listed moments when they land in this period.
- NEVER assert a market statistic, growth figure, or "this is going viral" claim that the brief did not explicitly supply. If the brief gives no number, plan no number.

# Growth strategy

If the user message contains a "Growth strategy (from this brand's own performance)" block, it is computed from this brand's REAL post metrics — treat it as the strongest steer for HOW to reach the audience:
- Lean the platform distribution toward the platforms with the highest reach share; don't starve a high-reach platform for an even split.
- Set each entry's scheduled_time intent toward the listed best posting times for its platform (the Writer/Scheduler honour it).
- Favour the listed winning hook patterns when shaping content_angle.
- Distribute the entries' objective toward the recommended objective distribution — these are the objectives that actually drove engagement and conversions for THIS brand, so the dead default is replaced by real signal.
- NEVER invent a number; the block already contains the only real figures.
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
                            'content_angle' => [
                                'type' => 'string',
                                'description' => 'One short phrase naming the hook direction for this entry (distinct from topic). Buildable into a scroll-stopping first line by the Writer.',
                            ],
                            'target_emotion' => [
                                'type' => 'string',
                                'enum' => ['curiosity', 'inspiration', 'trust', 'urgency', 'delight', 'belonging', 'aspiration', 'reassurance', 'pride', 'humour'],
                                'description' => 'The single dominant feeling this post should evoke. Vary across the month.',
                            ],
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
