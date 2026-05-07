<?php

namespace App\Services\Blotato;

use App\Models\Draft;
use App\Models\PlatformConnection;

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

        // ── Connection-level required overrides ──
        // Facebook (Pages API): Blotato requires `pageId` on every post,
        // even for personal-feed connections. Verified live 2026-05-07
        // after 4 prod posts failed HTTP 400 "body.post.target must have
        // required property 'pageId'".
        // Pinterest: requires `boardId`. Same shape.
        // Without a connection passed in, we can't check these — caller's
        // back-compat path. Surfaces at publish-time instead via Blotato 400.
        if ($connection !== null) {
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
        return $caption;
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
