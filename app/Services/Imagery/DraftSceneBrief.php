<?php

namespace App\Services\Imagery;

use App\Models\Draft;
use Illuminate\Support\Str;

/**
 * Builds the "scripted-content scene brief" — a compact summary of what a
 * post actually SAYS — so the generated image and video are anchored to the
 * same scripted content as the caption, and to each other.
 *
 * The problem this fixes: DesignerAgent and VideoAgent previously anchored
 * the visual to a raw truncated slice of draft.body plus the Strategist's
 * generic one-line visual_direction. They never saw the artefacts the
 * pipeline distils specifically to represent the post's message — the hook,
 * the principled quote, the CTA, the planned emotion. So the poster/clip had
 * no real relationship to the scripted post: it illustrated the first 24
 * words of the caption, not the point of the caption.
 *
 * DraftSceneBrief composes those scripted signals, in priority order, into a
 * short brief both agents feed to FAL:
 *   1. Hook / headline — the scroll-stopping idea (platform_payload.headline,
 *      else the first sentence of the body).
 *   2. Key message — the distilled quote (branding_payload.quote): the single
 *      most principled line the post stands on.
 *   3. Call to action — platform_payload.cta, when present.
 *   4. Target emotion — calendarEntry.research_brief.creative.target_emotion:
 *      the feeling the visual must evoke.
 *   5. Visual direction — the Strategist's art-direction one-liner.
 *   6. Context lead — a short body excerpt, last, as grounding only.
 *
 * Both DesignerAgent::buildPrompt() and VideoAgent::buildPrompt() consume the
 * SAME brief, so the still and the clip tell one coherent story.
 */
final class DraftSceneBrief
{
    /**
     * Compose the scene brief sentence(s). Returns '' only when the draft has
     * no usable scripted signal at all (empty body, no artefacts) — callers
     * degrade to their existing platform-aesthetic prompt in that case.
     *
     * @param  int  $contextWords  body-lead word budget (Designer uses ~24,
     *                             Video ~30 to leave room for motion language).
     */
    public static function for(Draft $draft, int $contextWords = 24): string
    {
        $parts = [];

        $hook = self::hook($draft);
        if ($hook !== '') {
            $parts[] = "The post's hook: \"{$hook}\"";
        }

        $quote = self::quote($draft);
        if ($quote !== '') {
            $parts[] = "Its core message: \"{$quote}\"";
        }

        $cta = self::cta($draft);
        if ($cta !== '') {
            $parts[] = "Call to action: {$cta}";
        }

        $emotion = self::targetEmotion($draft);
        if ($emotion !== '') {
            $parts[] = "Evoke this feeling: {$emotion}";
        }

        $direction = trim((string) ($draft->calendarEntry?->visual_direction ?? ''));
        if ($direction !== '') {
            $parts[] = "Art direction: {$direction}";
        }

        $lead = self::bodyLead($draft, $contextWords);
        if ($lead !== '') {
            $parts[] = "Caption context: {$lead}";
        }

        if (empty($parts)) {
            return '';
        }

        // One labelled block so the model treats it as the subject to depict,
        // not as instructions to render literally as text (the agents still
        // append their explicit NO-TEXT clause downstream).
        return 'Depict the meaning of this post (do NOT render this text in the image): '
            .implode('. ', $parts).'.';
    }

    /**
     * The voiceover script — the spoken narrative of a video. VideoAgent uses
     * this so the motion matches what the voice is saying. Empty when absent.
     */
    public static function voiceover(Draft $draft): string
    {
        $payload = is_array($draft->branding_payload) ? $draft->branding_payload : [];

        return trim((string) ($payload['voiceover'] ?? ''));
    }

    private static function hook(Draft $draft): string
    {
        $payload = is_array($draft->platform_payload) ? $draft->platform_payload : [];
        $headline = trim((string) ($payload['headline'] ?? ''));
        if ($headline !== '') {
            return self::clean($headline, 120);
        }

        // Fall back to the first sentence of the body — that line IS the hook
        // per the Writer's contract (the body must open with it).
        $body = trim(strip_tags((string) $draft->body));
        if ($body === '') {
            return '';
        }
        $firstSentence = preg_split('/(?<=[.!?])\s+/', $body, 2)[0] ?? $body;

        return self::clean($firstSentence, 120);
    }

    private static function quote(Draft $draft): string
    {
        $payload = is_array($draft->branding_payload) ? $draft->branding_payload : [];

        return self::clean(trim((string) ($payload['quote'] ?? '')), 140);
    }

    private static function cta(Draft $draft): string
    {
        $payload = is_array($draft->platform_payload) ? $draft->platform_payload : [];

        return self::clean(trim((string) ($payload['cta'] ?? '')), 90);
    }

    private static function targetEmotion(Draft $draft): string
    {
        $creative = $draft->calendarEntry?->research_brief['creative'] ?? null;
        if (! is_array($creative)) {
            return '';
        }

        return self::clean(trim((string) ($creative['target_emotion'] ?? '')), 60);
    }

    private static function bodyLead(Draft $draft, int $words): string
    {
        $body = trim(strip_tags((string) $draft->body));
        if ($body === '') {
            return '';
        }

        return (string) Str::words($body, max(8, $words), ' …');
    }

    /**
     * Strip newlines, hashtags, @mentions, URLs and emoji-ish noise so the
     * brief reads as a clean descriptive sentence — these would otherwise
     * leak into the prompt as literal tokens the model might try to render.
     */
    private static function clean(string $text, int $maxChars): string
    {
        $t = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $t = preg_replace('/https?:\/\/\S+/u', '', $t) ?? $t;
        $t = preg_replace('/[#@]\S+/u', '', $t) ?? $t;
        $t = trim($t);

        return mb_substr($t, 0, $maxChars);
    }
}
