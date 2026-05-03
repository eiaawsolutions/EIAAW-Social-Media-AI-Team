<?php

namespace App\Agents\Prompts;

final class WriterPrompt
{
    // v1.1 — added website_page source_type so the Writer can cite scraped
    // brand pages (the realistic shape of a brand corpus before historical
    // posts are imported). Bumping the version triggers redraft eligibility
    // for drafts that failed under v1.0 (see DraftsRedraftFailed query).
    public const VERSION = 'writer.v1.1';

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

    public static function system(string $platform): string
    {
        $limit = self::PLATFORM_LIMITS[$platform] ?? 1000;
        $platformLabel = ucfirst($platform);

        return <<<PROMPT
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

# Platform-specific guidance

PROMPT.self::platformGuide($platform)."\n\n# Provenance — grounding_sources field\n\nFor every concrete claim in your post, list the grounding source (which prior post / evidence quote / brand-style section anchored it). If a claim is generic (e.g. brand value statement) and supported by the brand-style, cite the brand-style section. Honesty here is the product — never claim a source you didn't actually use.";
    }

    public static function schema(string $platform): array
    {
        $limit = self::PLATFORM_LIMITS[$platform] ?? 1000;
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['body', 'hashtags', 'grounding_sources'],
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
            ],
        ];
    }

    private static function platformGuide(string $platform): string
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
