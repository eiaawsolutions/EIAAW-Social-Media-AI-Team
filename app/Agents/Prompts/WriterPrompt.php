<?php

namespace App\Agents\Prompts;

final class WriterPrompt
{
    // v1.3 — every draft is now REQUIRED to produce two short branded
    // artefacts alongside the body:
    //   - quote: 6–14 word declarative line stamped onto the Designer image
    //   - voiceover: 25–45 word script read aloud over the VideoAgent clip
    // These were previously distilled by QuoteWriter as a post-hoc Haiku
    // call. Folding them into Writer guarantees every draft has them, in
    // the same voice as the body, with no extra LLM call (Writer is using
    // Sonnet anyway). Designer + Video read these from draft.branding_payload.
    // Prod incident 2026-05-07: SP25 (YouTube) was sent a stamped JPEG
    // because Writer didn't produce a quote, QuoteWriter ran asynchronously
    // for Designer only, and the regen-image UI flow overwrote asset_url
    // with the JPEG. Locking Writer to produce the artefacts up-front is
    // the upstream fix that closes that class of bug at the source.
    //
    // v1.2 — appends learned platform-rejection rules from
    // compliance_learned_rules to the system prompt so the Writer doesn't
    // re-generate drafts that violate failure modes already observed in
    // prod (e.g. text-only on IG/TikTok, oversize captions, hashtag
    // explosions). Bumping the version makes prior compliance_failed drafts
    // eligible for redraft under the new prompt.
    // v1.4 — when a research_brief is present on the calendar entry, the
    // user message now includes 5 deepened angles with hook/thesis/evidence/
    // tension/audience. The Writer is asked to PICK ONE angle and draft from
    // it (rather than generating from the one-line strategist angle alone).
    // Falls back gracefully when the brief is null (Researcher off / failed).
    public const VERSION = 'writer.v1.4';

    /**
     * Per-platform character limits enforced both in the schema and in the
     * system prompt as instruction.
     */
    public const PLATFORM_LIMITS = [
        'instagram' => 2200,
        'facebook' => 2000,
        'linkedin' => 3000,
        'tiktok' => 2200,
        'threads' => 500,
        'x' => 280,
        'youtube' => 1000,
        'pinterest' => 500,
    ];

    public static function system(string $platform, ?int $workspaceId = null): string
    {
        $limit = self::PLATFORM_LIMITS[$platform] ?? 1000;
        $platformLabel = ucfirst($platform);

        $base = <<<PROMPT
You are EIAAW's senior copywriter, writing for {$platformLabel}. Your job is to draft one post grounded in the brand's voice and a calendar entry's specific topic.

# Hard rules

- Stay in the brand's voice. The brand-style.md is the single source of truth — your output must read like the brand wrote it.
- Ground every concrete claim in the supplied evidence. If the evidence doesn't say a metric or an outcome, don't claim it.
- Never invent statistics, awards, customer names, or quotes.
- When citing a corpus snippet in grounding_sources, source_id MUST be one of the [id=N] values shown verbatim in the user message. Do NOT invent IDs or default to "1", "2", "3" — copy the exact id from the prompt. If you can't find a fitting corpus snippet, cite brand_style instead.
- The source_type for each cited corpus snippet MUST match the [type=...] label shown next to its [id=N] in the prompt — use historical_post for posts, website_page for brand-website pages. Do NOT relabel a website_page as historical_post.
- For source_excerpt on corpus citations (historical_post or website_page), copy a 30+ character verbatim phrase from the [id=N] block — do NOT paraphrase. Compliance verifies citations by substring match.
- Match the platform's native format and conventions for {$platformLabel}.
- Stay within {$limit} characters for the body (excluding hashtags + mentions).
- Output ONLY the JSON document specified by the schema. No commentary.

# Branded artefacts — REQUIRED on every draft

Every draft MUST also produce two short artefacts that get rendered onto
the post's image and video. Same voice as the body, distilled from your
own draft. NEVER invent — if the body doesn't say it, neither artefact
does.

1. quote — for image stamping
   - 6 to 14 words. One sentence ending in a period.
   - Sentence case (no Title Case, no ALL CAPS).
   - Distils the SINGLE most principled idea in your body.
   - No hashtags, emojis, URLs, @mentions, or wrapping quote marks.
   - Visually scannable in 1.5 seconds.
   - If the post has no principle to distil (announcement / metric / job
     post), make a quiet present-tense observation tied to the topic.

2. voiceover — for video voiceover
   - 25 to 45 words across two or three short sentences.
   - For 5–8 second short-form video at ~150 wpm.
   - Reads aloud naturally — punctuation cues breath.
   - Ends on a complete thought (no "..." cliff-hanger).
   - No URLs spelled out, no hashtag reading, no "link in bio", no "swipe
     up", no "comment below". Same voice as the quote.

# Platform-specific guidance

PROMPT.self::platformGuide($platform)."\n\n# Research brief (when supplied)\n\nIf the user message contains a 'Research brief — 5 angles' block, treat each angle as a candidate direction. Pick the SINGLE angle that best fits the platform + format + objective and draft from it. Use that angle's `evidence` verbatim where it strengthens a claim. Do NOT mash multiple angles together — pick one and commit. If no brief is supplied, draft from the one-line angle on the calendar entry.\n\n# Provenance — grounding_sources field\n\nFor every concrete claim in your post, list the grounding source (which prior post / evidence quote / brand-style section anchored it). If a claim is generic (e.g. brand value statement) and supported by the brand-style, cite the brand-style section. Honesty here is the product — never claim a source you didn't actually use.";

        // Append learned-rules memory. Best-effort: if the service or DB is
        // unavailable we still ship the base prompt — the Writer will at
        // worst re-trip a known failure and Compliance will catch it.
        try {
            $directive = app(\App\Services\Compliance\LearnedRulesProvider::class)
                ->promptDirectiveFor($platform, $workspaceId);
            if ($directive !== '') {
                $base .= "\n\n" . $directive;
            }
        } catch (\Throwable) {
            // swallow — base prompt still safe
        }

        return $base;
    }

    public static function schema(string $platform): array
    {
        $limit = self::PLATFORM_LIMITS[$platform] ?? 1000;
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['body', 'hashtags', 'grounding_sources', 'quote', 'voiceover'],
            'properties' => [
                'body' => [
                    'type' => 'string',
                    // Anthropic's structured-output validator rejects
                    // maxLength on string types. Per-platform cap ($limit)
                    // is enforced in the system prompt and truncated in
                    // WriterAgent before persisting to drafts.
                    'description' => "Caption body. Hard cap: {$limit} chars for {$platform}.",
                ],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    // Anthropic's structured-output validator rejects bounded
                    // maxItems on some array types — keep the cap in the
                    // system prompt ("max 30 hashtags") and trim in PHP
                    // before persisting to the draft if the model overruns.
                    'description' => 'No # prefix. Just the words. Max 30.',
                ],
                'mentions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Handles to @-mention if relevant. Without the @ prefix.',
                ],
                'grounding_sources' => [
                    'type' => 'array',
                    'description' => 'For every concrete claim in body, the source that supports it.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['claim', 'source_type', 'source_excerpt'],
                        'properties' => [
                            'claim' => ['type' => 'string', 'description' => 'The phrase from your body that this grounds.'],
                            'source_type' => ['type' => 'string', 'enum' => ['brand_style', 'historical_post', 'website_page', 'evidence_quote', 'calendar_entry']],
                            'source_excerpt' => ['type' => 'string', 'description' => 'Short verbatim quote from the source.'],
                            'source_id' => ['type' => 'string', 'description' => 'Optional row id from brand_corpus / brand_styles / calendar_entries when known.'],
                        ],
                    ],
                ],
                'visual_direction' => [
                    'type' => 'string',
                    'description' => 'Brief for the Designer agent — what image/video would best accompany this post.',
                ],
                'quote' => [
                    'type' => 'string',
                    'description' => '6–14 word declarative line in sentence case, ending in a period. Stamped onto the Designer image. Same voice as the body. Distils the single most principled idea. No hashtags, emojis, URLs, @mentions, or wrapping quote marks.',
                ],
                'voiceover' => [
                    'type' => 'string',
                    'description' => '25–45 word script (2–3 short sentences) for the VideoAgent voiceover at ~150 wpm. Reads aloud naturally. No URLs spelled out, no hashtag reading, no "link in bio", no "swipe up". Same voice as quote.',
                ],
            ],
        ];
    }

    public static function platformGuide(string $platform): string
    {
        return match ($platform) {
            'linkedin' => "- Open with a specific hook line that earns the click.\n- Short paragraphs. Whitespace = readability.\n- Insight-led, professional-but-human voice. First-person experience > generic advice.\n- 3-5 hashtags at the end.",
            'x' => "- Standalone hook. No 'Here's what I learned' preamble.\n- Punchy. Every word earns its place.\n- 280 chars max — count yourself.",
            'threads' => "- Short, direct, opinion-led. Built for conversation.\n- 500 chars max.\n- Hashtags rarely useful here.",
            'instagram' => "- First line is the scroll-stopper. Treat it like a headline.\n- Short paragraphs. Use line breaks generously.\n- 5-15 hashtags blended naturally or stacked at end.",
            'tiktok' => "- Hook in the first 3 seconds (caption sets up the video premise).\n- Lower-case, conversational. No corporate language.\n- 3-5 hashtags, mix of broad + niche.",
            'facebook' => "- Slightly longer-form OK here. Conversational.\n- First line still a hook. Question-led works.\n- Hashtags optional.",
            'youtube' => "- Strong title-style first line.\n- Use it as a description: what's in the video, why it matters.\n- 3-5 keywords as hashtags.",
            'pinterest' => "- Searchable. Front-load keywords in first 100 chars.\n- Description-style, not chatty.\n- Up to 20 hashtags, all keyword-relevant.",
            default => "- Match the platform's native conventions.",
        };
    }
}
