<?php

namespace App\Services\Publishing;

/**
 * Single source of truth for "is this Blotato status payload actually proof
 * that the post landed on the platform?"
 *
 * Background: Blotato returns state=published before its platform adapters
 * (TikTok/YouTube/IG/Threads/LinkedIn) have actually delivered the post. We
 * historically trusted that field and ended up with 32 prod rows marked
 * `published` while only 1 actually appeared on TikTok and 0 on YouTube.
 *
 * This class encodes a stricter truth condition: a post is verified-published
 * iff Blotato returned EITHER:
 *   - a non-empty `platformPostId` (the platform's own internal id), OR
 *   - a `platformPostUrl` whose path matches a real-post pattern for that
 *     platform (NOT the brand's profile root).
 *
 * If neither is present, the post stays in `submitted` state so the poller
 * keeps trying. If repeated polling never resolves a real id/url, the row
 * eventually times out via existing retry semantics.
 *
 * Used by:
 *   - SubmitScheduledPost::pollAndAdvance (live publish-time guard)
 *   - PostsReconcilePublished command (clean up the historical mess)
 */
final class PostVerificationRules
{
    /**
     * Return true iff the platform_post_url is a real post URL on its
     * platform (not a profile root, not empty, not a search page).
     *
     * Real-post URL patterns per platform (verified against live posts):
     *   instagram   /p/<id>/   /reel/<id>/    /tv/<id>/
     *   tiktok      /video/<numeric>           /photo/<id>
     *   youtube     /watch?v=<id>              /shorts/<id>     youtu.be/<id>
     *   threads     /post/<id>                 /@user/post/<id>
     *   linkedin    /feed/update/urn:li:...    /posts/<slug>    /pulse/<slug>
     *   facebook    /<page>/posts/<id>         /watch/?v=<id>   /reel/<id>
     *   x/twitter   /<user>/status/<id>
     *   pinterest   /pin/<numeric>
     *
     * Anything else — including a bare profile URL like
     * https://www.tiktok.com/@eiaawsolutions — fails verification.
     */
    public static function isRealPostUrl(string $platform, ?string $url): bool
    {
        if ($url === null || trim($url) === '') return false;
        $url = trim($url);

        return match (strtolower($platform)) {
            'instagram' => (bool) preg_match('#instagram\.com/(?:p|reel|tv)/[A-Za-z0-9_-]+#i', $url),
            'tiktok' => (bool) preg_match('#tiktok\.com/(?:@[^/]+/)?(?:video|photo)/\d+#i', $url),
            'youtube' => (bool) preg_match('#(?:youtube\.com/(?:watch\?v=|shorts/|live/)|youtu\.be/)[A-Za-z0-9_-]{6,}#i', $url),
            'threads' => (bool) preg_match('#threads\.(?:net|com)/.+/post/[A-Za-z0-9_-]+#i', $url),
            'linkedin' => (bool) preg_match('#linkedin\.com/(?:feed/update/urn:li:|posts/|pulse/)#i', $url),
            'facebook' => (bool) preg_match('#facebook\.com/(?:[^/]+/(?:posts|videos)/|reel/|watch/?\?v=|story\.php\?)#i', $url),
            'x', 'twitter' => (bool) preg_match('#(?:x\.com|twitter\.com)/[^/]+/status/\d+#i', $url),
            'pinterest' => (bool) preg_match('#pinterest\.com/pin/\d+#i', $url),
            'bluesky' => (bool) preg_match('#bsky\.app/profile/[^/]+/post/[A-Za-z0-9]+#i', $url),
            default => false,
        };
    }

    /**
     * Verification verdict for a Blotato-side status payload + the captured
     * platform_post_id/url pair we'd save.
     *
     * @return array{verified:bool, reason:string}
     */
    public static function verify(string $platform, ?string $platformPostId, ?string $platformPostUrl): array
    {
        $hasId = is_string($platformPostId) && trim($platformPostId) !== '';
        $hasRealUrl = self::isRealPostUrl($platform, $platformPostUrl);

        if ($hasId && $hasRealUrl) {
            return ['verified' => true, 'reason' => 'platform_post_id + verified post URL'];
        }
        if ($hasId) {
            return ['verified' => true, 'reason' => 'platform_post_id present'];
        }
        if ($hasRealUrl) {
            return ['verified' => true, 'reason' => 'verified post URL pattern'];
        }

        // Specifically diagnose the common failure mode: profile-root URL.
        if ($platformPostUrl && self::looksLikeProfileRoot($platform, $platformPostUrl)) {
            return [
                'verified' => false,
                'reason' => 'platform_post_url is a profile root, not a post permalink',
            ];
        }

        return [
            'verified' => false,
            'reason' => 'no platform_post_id and no verified post URL — Blotato did not confirm platform-side delivery',
        ];
    }

    private static function looksLikeProfileRoot(string $platform, string $url): bool
    {
        $url = rtrim(trim($url), '/');
        // Profile root patterns — e.g. tiktok.com/@user, x.com/user,
        // instagram.com/user, youtube.com/@user. Heuristic, not exhaustive.
        return match (strtolower($platform)) {
            'tiktok' => (bool) preg_match('#^https?://(?:www\.)?tiktok\.com/@[^/]+$#i', $url),
            'instagram' => (bool) preg_match('#^https?://(?:www\.)?instagram\.com/[^/]+$#i', $url),
            'threads' => (bool) preg_match('#^https?://(?:www\.)?threads\.(?:net|com)/@[^/]+$#i', $url),
            'youtube' => (bool) preg_match('#^https?://(?:www\.)?youtube\.com/@[^/]+$#i', $url),
            'x', 'twitter' => (bool) preg_match('#^https?://(?:www\.)?(?:x|twitter)\.com/[^/]+$#i', $url),
            'facebook' => (bool) preg_match('#^https?://(?:www\.)?facebook\.com/[^/]+$#i', $url),
            'pinterest' => (bool) preg_match('#^https?://(?:www\.)?pinterest\.com/[^/]+$#i', $url),
            'linkedin' => (bool) preg_match('#^https?://(?:www\.)?linkedin\.com/(?:in|company)/[^/]+$#i', $url),
            default => false,
        };
    }
}
