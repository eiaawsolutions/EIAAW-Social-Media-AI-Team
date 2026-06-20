<?php

namespace App\Services\Blotato;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Services\Imagery\EiaawBrandLock;

/**
 * Single source of truth for per-platform Blotato + native API publishability
 * rules. Compliance, Writer, and SubmitScheduledPost all consume from here.
 *
 * Sourced from:
 *   - Blotato OpenAPI v2 (backend.blotato.com/openapi.json verified 2026-05-02)
 *   - Live 422/400 responses observed in prod (logged 2026-05-03 -> 2026-05-05)
 *   - Native platform docs as of Q2 2026
 *
 * Rules are intentionally deterministic + cheap — Compliance runs
 * `evaluate()` BEFORE the LLM voice scorer so we never burn tokens on a
 * draft that can't physically publish.
 *
 * Failure semantics: every rule that fails returns a structured RuleViolation
 * with a `kind` (so RedraftFailedDraft can route — text fixes go to Writer,
 * media fixes go to Designer/Video) and an operator-facing reason.
 */
final class PlatformRules
{
    /**
     * Per-platform rule manifest.
     *
     * Keys:
     *   - caption_max_total : hard cap on body + hashtag block + mentions block (chars)
     *   - hashtag_cap       : max hashtags Blotato will accept (NOT what the platform allows
     *                         natively — Blotato is more restrictive on IG: 5 vs 30 native)
     *   - media_required    : true = no asset_url -> reject; false = text-only allowed
     *   - media_min         : minimum number of media items (e.g. carousel formats)
     *
     * Scope: ONLY rules that block any auto-post regardless of account type.
     * Connection-config concerns (e.g. Facebook Page pageId vs personal-
     * profile no-pageId) live in target_overrides on PlatformConnection and
     * are NOT enforced here — both shapes are valid and Blotato handles both.
     *
     * Sources: Instagram/TikTok/YouTube media-required confirmed via prod
     * 422/empty-reject responses 2026-05-05. Threads/X/LinkedIn/Facebook
     * allow text-only on both personal and Page connections.
     */
    public const RULES = [
        'instagram' => [
            'caption_max_total' => 2200,
            'hashtag_cap' => 5,        // Blotato cap; native IG allows 30
            'media_required' => true,
            'media_min' => 1,
        ],
        'facebook' => [
            'caption_max_total' => 63206,
            'hashtag_cap' => 30,
            'media_required' => false, // text-only Facebook posts allowed
            'media_min' => 0,
        ],
        'linkedin' => [
            'caption_max_total' => 3000,
            'hashtag_cap' => 30,
            'media_required' => false,
            'media_min' => 0,
        ],
        'tiktok' => [
            'caption_max_total' => 2200,
            'hashtag_cap' => 30,
            'media_required' => true, // TikTok rejects text-only with 422
            'media_min' => 1,
        ],
        'youtube' => [
            'caption_max_total' => 1000,
            'hashtag_cap' => 15,
            'media_required' => true, // YouTube rejects text-only
            'media_min' => 1,
        ],
        'pinterest' => [
            'caption_max_total' => 500,
            'hashtag_cap' => 20,
            'media_required' => true,
            'media_min' => 1,
        ],
        'threads' => [
            'caption_max_total' => 500,
            'hashtag_cap' => 10,
            'media_required' => false,
            'media_min' => 0,
        ],
        'x' => [
            'caption_max_total' => 280,
            'hashtag_cap' => 5,
            'media_required' => false,
            'media_min' => 0,
        ],
        'twitter' => [ // legacy alias for x
            'caption_max_total' => 280,
            'hashtag_cap' => 5,
            'media_required' => false,
            'media_min' => 0,
        ],
    ];

    public const DEFAULT_RULE = [
        'caption_max_total' => 1000,
        'hashtag_cap' => 10,
        'media_required' => false,
        'media_min' => 0,
    ];

    public static function for(string $platform): array
    {
        return self::RULES[strtolower($platform)] ?? self::DEFAULT_RULE;
    }

    /**
     * Evaluate a draft against its platform's publishability rules.
     *
     * Two layers of checks:
     *   1. Draft-level — rules that block any auto-post regardless of which
     *      account it goes to (caption length, hashtag count, media).
     *   2. Connection-level — rules that depend on the platform_connection
     *      target_overrides. Currently: Facebook requires `pageId`,
     *      Pinterest requires `boardId`. Both Blotato adapters reject
     *      HTTP 400 without these. Caller may omit $connection to skip
     *      connection-level checks (back-compat with pre-2026-05-07
     *      callers); when omitted, those checks are silently skipped and
     *      surface only at publish time.
     *
     * @return array{passed:bool, violations:array<int,array{kind:string,reason:string,detail:array}>}
     */
    public static function evaluate(Draft $draft, ?PlatformConnection $connection = null): array
    {
        $rule = self::for((string) $draft->platform);
        $violations = [];

        // ── Caption length (hard cap, after assembly) ──
        $caption = self::assembleCaption($draft, $rule);
        $captionLen = mb_strlen($caption);
        if ($captionLen > $rule['caption_max_total']) {
            $violations[] = [
                'kind' => 'caption_too_long',
                'reason' => sprintf(
                    '%s caption is %d chars; platform cap is %d. Tighten body and/or trim hashtags.',
                    ucfirst($draft->platform),
                    $captionLen,
                    $rule['caption_max_total'],
                ),
                'detail' => [
                    'platform' => $draft->platform,
                    'caption_length' => $captionLen,
                    'cap' => $rule['caption_max_total'],
                ],
            ];
        }

        // ── Hashtag count cap ──
        $hashtags = is_array($draft->hashtags) ? $draft->hashtags : [];
        if (count($hashtags) > $rule['hashtag_cap']) {
            $violations[] = [
                'kind' => 'too_many_hashtags',
                'reason' => sprintf(
                    '%s accepts at most %d hashtags via Blotato; you have %d. Drop the lowest-impact ones.',
                    ucfirst($draft->platform),
                    $rule['hashtag_cap'],
                    count($hashtags),
                ),
                'detail' => [
                    'platform' => $draft->platform,
                    'hashtag_count' => count($hashtags),
                    'cap' => $rule['hashtag_cap'],
                ],
            ];
        }

        // ── Hashtag content sanity (catches the #32-style "hashtag array
        //    contains the entire post body" bug we saw in prod) ──
        foreach ($hashtags as $i => $tag) {
            $tag = (string) $tag;
            if (mb_strlen($tag) > 100) {
                $violations[] = [
                    'kind' => 'malformed_hashtag',
                    'reason' => sprintf(
                        'Hashtag #%d is %d chars — looks like the post body got captured into the hashtag array. Re-extract hashtags as short keywords only.',
                        $i + 1,
                        mb_strlen($tag),
                    ),
                    'detail' => [
                        'platform' => $draft->platform,
                        'index' => $i,
                        'length' => mb_strlen($tag),
                        'preview' => mb_substr($tag, 0, 80),
                    ],
                ];
                break; // one is enough; don't spam
            }
        }

        // ── Media requirement ──
        if ($rule['media_required']) {
            $mediaCount = self::countMedia($draft);
            if ($mediaCount < $rule['media_min']) {
                $violations[] = [
                    'kind' => 'media_required',
                    'reason' => sprintf(
                        '%s requires at least %d media item(s). Designer/Video must attach an image or video before publish.',
                        ucfirst($draft->platform),
                        $rule['media_min'],
                    ),
                    'detail' => [
                        'platform' => $draft->platform,
                        'media_present' => $mediaCount,
                        'media_required' => $rule['media_min'],
                    ],
                ];
            }
        }

        // ── Calendar-format media intent ──
        // The calendar entry's `format` declares the operator's intent
        // (single_image / carousel / reel / video / story). When the intent
        // is a media format, the draft MUST have an asset_url — regardless
        // of whether the platform technically allows text-only.
        //
        // Why this is separate from the platform-level `media_required`
        // rule above: LinkedIn + Threads + Facebook + X all accept
        // text-only posts (rule['media_required']=false), so the existing
        // gate doesn't block a text-only post on those platforms. But if
        // the operator's calendar said "single_image", shipping naked text
        // is a quiet failure of intent — the post has no visual anchor on
        // a feed where every other post does, and the operator wonders
        // why their content looks half-finished.
        //
        // Verified live 2026-05-09: 25 of 39 published prod posts shipped
        // text-only when the calendar entry asked for media. 12 of 18
        // currently-queued posts will repeat the failure. This gate stops
        // both: compliance flips them to compliance_failed (so the redraft
        // loop can re-run Designer/Video), and the publish-time gate in
        // SubmitScheduledPost catches anything that slipped through.
        $entry = $draft->calendarEntry;
        if ($entry !== null) {
            $entryFormat = strtolower((string) ($entry->format ?? ''));
            $mediaFormats = ['single_image', 'carousel', 'reel', 'video', 'story'];
            if (in_array($entryFormat, $mediaFormats, true) && self::countMedia($draft) === 0) {
                $videoFormats = ['reel', 'video', 'story'];
                $needsVideo = in_array($entryFormat, $videoFormats, true);
                $violations[] = [
                    'kind' => $needsVideo ? 'media_required' : 'calendar_format_media_missing',
                    'reason' => sprintf(
                        'Calendar entry asks for format=%s but draft has no asset_url. '
                        . '%s must attach %s before publish — or change the calendar entry format to text_only.',
                        $entryFormat,
                        $needsVideo ? 'VideoAgent (and Designer for the keyframe)' : 'DesignerAgent',
                        $needsVideo ? 'a video (.mp4)' : 'an image',
                    ),
                    'detail' => [
                        'platform' => $draft->platform,
                        'calendar_format' => $entryFormat,
                        'expected_media_type' => $needsVideo ? 'video' : 'image',
                        'asset_url_present' => false,
                    ],
                ];
            }
        }

        // ── Connection-level required overrides (BLOTATO-ONLY) ──
        // Facebook (Pages API): Blotato requires `pageId` on every post, even
        // for personal-feed connections (verified live 2026-05-07: 4 prod posts
        // failed HTTP 400 "body.post.target must have required property
        // 'pageId'"). Pinterest: requires `boardId`. Same shape.
        //
        // These are BLOTATO requirements and must NOT be enforced under
        // Metricool: Metricool's ScheduledPostFacebookData has NO `pageId` field
        // (it routes to the Page by the brand's connected profile) — sending one
        // is rejected HTTP 400 "Unrecognized field 'pageId'". Enforcing the gate
        // under Metricool created a deadlock: no pageId → this gate blocks; with
        // pageId → Metricool's scheduler rejects it. So gate only on blotato.
        // Without a connection passed in we can't check these (back-compat path).
        $provider = strtolower((string) config('services.publishing.provider', 'metricool')) ?: 'metricool';
        if ($connection !== null && $provider === 'blotato') {
            $platform = strtolower((string) $draft->platform);
            $overrides = is_array($connection->target_overrides) ? $connection->target_overrides : [];

            if ($platform === 'facebook' && empty($overrides['pageId'])) {
                $violations[] = [
                    'kind' => 'missing_facebook_page_id',
                    'reason' => 'Facebook requires a Page id. '
                        . 'Set platform_connection.target_overrides.pageId to the numeric Facebook Page id '
                        . '(NOT the Blotato accountId, NOT a username) in /agency/platforms → Target overrides. '
                        . 'Personal-profile auto-posting was deprecated by Meta in 2024.',
                    'detail' => [
                        'platform' => 'facebook',
                        'connection_id' => $connection->id,
                        'override_keys_present' => array_keys($overrides),
                    ],
                ];
            }

            if ($platform === 'pinterest' && empty($overrides['boardId'])) {
                $violations[] = [
                    'kind' => 'missing_pinterest_board_id',
                    'reason' => 'Pinterest requires a board id. '
                        . 'Set platform_connection.target_overrides.boardId in /agency/platforms → Target overrides.',
                    'detail' => [
                        'platform' => 'pinterest',
                        'connection_id' => $connection->id,
                        'override_keys_present' => array_keys($overrides),
                    ],
                ];
            }
        }

        return [
            'passed' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Mirror of SubmitScheduledPost::caption assembly so Compliance and the
     * publish path agree on what gets sent. Hashtags/mentions are joined with
     * the same separators; if those change here they MUST change there.
     */
    private static function assembleCaption(Draft $draft, array $rule): string
    {
        $body = trim((string) $draft->body);
        $hashtags = array_slice(
            is_array($draft->hashtags) ? $draft->hashtags : [],
            0,
            $rule['hashtag_cap'],
        );
        $mentions = is_array($draft->mentions) ? $draft->mentions : [];

        $caption = $body;
        if ($hashtags) {
            $caption .= "\n\n" . implode(' ', array_map(fn ($t) => '#' . ltrim((string) $t, '#'), $hashtags));
        }
        if ($mentions) {
            $caption .= "\n" . implode(' ', array_map(fn ($m) => '@' . ltrim((string) $m, '@'), $mentions));
        }

        // HQ-only call-to-action links, appended last so they survive the same
        // length check the platform enforces (Compliance counts the assembled
        // caption — see evaluate()). Empty string for client brands / non-HQ /
        // disabled config, keeping their caption byte-identical to before.
        $cta = self::hqCtaBlock($draft);
        if ($cta !== '') {
            $caption .= "\n\n" . $cta;
        }

        return $caption;
    }

    /**
     * The fixed call-to-action block appended to every EIAAW HQ post's caption.
     * Returns '' unless the draft's brand is the HQ brand (eiaaw_internal plan)
     * AND services.hq_cta.enabled is true — so client brands are never touched.
     *
     * Per-platform: on link-friendly platforms (caption URLs are clickable) the
     * full labelled URLs are appended; on platforms that don't linkify caption
     * URLs (instagram/tiktok/youtube/pinterest), a single "links in bio" line is
     * used instead (raw URLs there aren't clickable and suppress reach).
     *
     * This is the SINGLE source of the CTA text — both the compliance caption
     * assembler (assembleCaption) and the publish path (SubmitScheduledPost)
     * call it, so what Compliance counts is exactly what ships.
     */
    public static function hqCtaBlock(Draft $draft): string
    {
        $brand = $draft->brand;
        if (! $brand instanceof Brand) {
            return '';
        }

        return self::ctaTextFor($brand, (string) $draft->platform);
    }

    /**
     * Core CTA-text generator for a (brand, platform) pair — no Draft required,
     * so both the caption assemblers (hqCtaBlock) and the Writer's body-cap
     * reservation (hqCtaReservedChars) share the EXACT same text. Returns '' for
     * non-HQ brands / disabled config / no links.
     */
    private static function ctaTextFor(Brand $brand, string $platform): string
    {
        $cfg = config('services.hq_cta', []);
        if (! ($cfg['enabled'] ?? false) || ! EiaawBrandLock::appliesTo($brand)) {
            return '';
        }

        $links = is_array($cfg['links'] ?? null) ? $cfg['links'] : [];
        if ($links === []) {
            return '';
        }

        $platform = strtolower($platform);
        $linkFriendly = is_array($cfg['link_friendly_platforms'] ?? null)
            ? $cfg['link_friendly_platforms'] : [];

        if (in_array($platform, $linkFriendly, true)) {
            // Full labelled URLs, one per line.
            $lines = [];
            foreach ($links as $link) {
                $label = trim((string) ($link['label'] ?? ''));
                $url = trim((string) ($link['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $lines[] = $label !== '' ? "{$label}: {$url}" : $url;
            }

            return implode("\n", $lines);
        }

        // Non-link-friendly platform → a single bio-pointer line, no raw URLs.
        return trim((string) ($cfg['bio_line'] ?? ''));
    }

    /**
     * Characters the appended CTA block will cost in the caption for a (brand,
     * platform) pair — the CTA text length PLUS its "\n\n" separator. 0 when no
     * CTA applies (client brand / non-HQ / disabled). The Writer/Repurpose body
     * cap subtracts this so a full-length body isn't truncated at publish to fit
     * the links. Mirrors how assembleCaption / SubmitScheduledPost append it.
     */
    public static function hqCtaReservedChars(Brand $brand, string $platform): int
    {
        $cta = self::ctaTextFor($brand, $platform);

        return $cta === '' ? 0 : mb_strlen($cta) + 2; // +2 for the "\n\n" separator
    }

    /**
     * Count attached media items on the draft. asset_url + asset_urls are
     * deduped (same as collectDraftMediaUrls in SubmitScheduledPost).
     */
    private static function countMedia(Draft $draft): int
    {
        $urls = [];
        if ($draft->asset_url) {
            $urls[] = (string) $draft->asset_url;
        }
        if (is_array($draft->asset_urls)) {
            foreach ($draft->asset_urls as $u) {
                if (is_string($u) && $u !== '') $urls[] = $u;
            }
        }
        return count(array_unique($urls));
    }
}
