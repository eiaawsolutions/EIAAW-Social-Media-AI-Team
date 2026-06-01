<?php

namespace App\Services\Publishing;

use App\Models\ScheduledPost;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Publishes via Metricool's /v2/scheduler/posts. The default publisher after
 * the Blotato→Metricool switch (PUBLISH_PROVIDER=metricool).
 *
 * Account targeting: Metricool is multi-brand — a post targets the brand's
 * Metricool blogId (brands.metricool_blog_id) + the network. There is no
 * per-account id like Blotato's blotato_account_id; Metricool routes to the
 * single account it has connected for that network within the brand. So
 * targeting needs only (blogId, network), which removes the entire
 * PlatformSyncService/listAccounts dependency from the publish path.
 *
 * Per-platform options (Blotato target.* → Metricool *Data): TikTok privacy +
 * flags, YouTube title/privacy/notify, Linkedin/Facebook page selection,
 * Pinterest board, Threads reply control. Built from the connection's
 * target_overrides (the same column Blotato used) so operators keep one config
 * surface.
 *
 * Verification: Metricool's scheduler returns a post id, but the live platform
 * URL only appears once the post is delivered — we read it back from the
 * brand's analytics/posts list and require PostVerificationRules to pass before
 * reporting `published` (same strict gate Blotato uses; never trust a bare
 * "scheduled" status).
 *
 * IMPORTANT: we own scheduling in Postgres, so publicationDate is "now" and
 * autoPublish=true — Metricool publishes immediately, exactly like the Blotato
 * scheduledTime=null path.
 */
class MetricoolPublisher implements Publisher
{
    public function __construct(private readonly MetricoolClient $client) {}

    public function key(): string
    {
        return 'metricool';
    }

    public function submit(ScheduledPost $post, string $caption, array $mediaUrls): PublishResult
    {
        $blogId = $post->brand?->metricool_blog_id;
        if (! $blogId) {
            return PublishResult::failed(
                'Brand #' . ($post->brand_id ?? '?') . ' has no metricool_blog_id. '
                . 'Map the brand to a Metricool brand before publishing via Metricool.'
            );
        }

        $network = $this->networkFor((string) ($post->draft?->platform ?? ''));
        if ($network === null) {
            return PublishResult::failed('Unsupported platform for Metricool: ' . ($post->draft?->platform ?? '?'));
        }

        // Normalise each media URL (Metricool requires re-hosted media, like
        // Blotato's /v2/media). A failure here is a hard fail — no half-posts.
        $media = [];
        foreach ($mediaUrls as $url) {
            $norm = $this->client->normalizeMedia($url);
            if (! ($norm['found'] ?? false)) {
                return PublishResult::failed('Media normalize failed for ' . $url
                    . ' (HTTP ' . ($norm['status'] ?? '?') . ')');
            }
            $media[] = $this->extractMediaRef($norm['body']);
        }

        $perNetworkData = $this->perNetworkData($network, $post, $caption);

        try {
            $res = $this->client->schedulePost(
                blogId: (int) $blogId,
                networks: [$network],
                text: $caption,
                // We own scheduling; publish now.
                publicationDateTime: Carbon::now()->format('Y-m-d\TH:i:s'),
                timezone: (string) ($post->brand?->workspace?->settings['timezone'] ?? config('app.timezone', 'Asia/Kuala_Lumpur')),
                media: array_values(array_filter($media)),
                autoPublish: true,
                perNetworkData: $perNetworkData,
            );
        } catch (\Throwable $e) {
            return PublishResult::failed('Metricool schedulePost failed: ' . substr($e->getMessage(), 0, 220));
        }

        $providerId = $this->extractPostId($res['response'] ?? null);
        if ($providerId === null) {
            // Accepted (2xx) but no id we recognise — keep it pending; poll
            // will reconcile via the analytics list by URL if needed.
            return PublishResult::pending(raw: is_array($res['response'] ?? null) ? $res['response'] : null);
        }

        return PublishResult::submitted($providerId, is_array($res['response'] ?? null) ? $res['response'] : null);
    }

    public function poll(ScheduledPost $post): PublishResult
    {
        $blogId = $post->brand?->metricool_blog_id;
        $network = $this->networkFor((string) ($post->draft?->platform ?? ''));
        if (! $blogId || $network === null) {
            return PublishResult::pending();
        }

        // Metricool exposes the live post (with its platform URL) via the same
        // analytics/posts list the metrics collector uses. We match by the
        // captured platform_post_url when we have one; otherwise we cannot yet
        // verify and stay pending.
        $knownUrl = (string) ($post->platform_post_url ?? '');

        try {
            $result = $this->client->postAnalytics(
                blogId: (int) $blogId,
                from: Carbon::now()->subDays(7)->startOfDay()->format('Y-m-d\TH:i:s'),
                to: Carbon::now()->endOfDay()->format('Y-m-d\TH:i:s'),
                network: $network,
            );
        } catch (\Throwable $e) {
            Log::warning('MetricoolPublisher: poll list failed (will retry)', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return PublishResult::pending();
        }

        if (! ($result['found'] ?? false)) {
            return PublishResult::pending();
        }

        // Find the just-published post. With no platform URL yet, match on the
        // provider post id captured at submit (stored as blotato_post_id — the
        // generic "provider submission id" column, reused).
        $match = $this->locatePost($result['body'], $knownUrl, (string) ($post->blotato_post_id ?? ''));
        if ($match === null) {
            return PublishResult::pending();
        }

        $url = (string) ($match['url'] ?? $match['shareUrl'] ?? $match['postUrl'] ?? '');
        $platformId = isset($match['postId']) ? (string) $match['postId']
            : (isset($match['videoId']) ? (string) $match['videoId'] : null);

        $platform = (string) ($post->draft?->platform ?? '');
        $verdict = PostVerificationRules::verify($platform, $platformId, $url);
        if (! $verdict['verified']) {
            // Reached the post but it isn't a verifiable permalink yet.
            return PublishResult::pending(raw: $match);
        }

        return PublishResult::published($platformId, $url, $match);
    }

    /** Map our internal platform enum to Metricool's network slug. */
    private function networkFor(string $platform): ?string
    {
        $platform = strtolower($platform);
        $known = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'pinterest', 'threads', 'x', 'twitter', 'bluesky'];
        if (! in_array($platform, $known, true)) {
            return null;
        }
        return $platform === 'x' ? 'twitter' : $platform;
    }

    /**
     * Build the per-network options block (Metricool's `<network>Data`) from
     * the connection's target_overrides plus safe defaults. Mirrors the intent
     * of BlotatoClient::defaultTargetFor but in Metricool's shape.
     *
     * CRITICAL (the 2026-06-01 deserialization bug): Metricool's scheduler
     * expects each `<network>Data` to be a JSON OBJECT. An empty PHP array
     * `[]` serialises to JSON `[]` (a JSON array), which Metricool rejects with
     * HTTP 400 "Cannot deserialize instance of …Data out of START_ARRAY". So
     * we NEVER emit an empty block — a network with no options simply omits its
     * `*Data` key entirely (exactly how instagram already behaves, which is why
     * instagram posts were the only ones that survived). The trailing
     * array_filter on the whole result enforces this for every branch.
     *
     * Likewise we send ONLY fields Metricool's scheduler recognises. Unverified
     * fields (e.g. an earlier youtubeData.notifySubscribers/madeForKids guess)
     * trigger HTTP 400 "Unrecognized field" and fail the whole post, so they are
     * removed. Add a field back only after it is confirmed against the live API.
     *
     * @return array<string,mixed>  e.g. ['tiktokData' => [...]] — merged into body
     */
    private function perNetworkData(string $network, ScheduledPost $post, string $caption): array
    {
        $ov = is_array($post->platformConnection?->target_overrides)
            ? $post->platformConnection->target_overrides
            : [];

        $block = match ($network) {
            // TikTok field names verified against Metricool's Swagger spec
            // (ScheduledPostTikTokData, 2026-06-01): the valid set is
            // disableComment/Duet/Stitch, privacyOption, commercialContent*,
            // title, autoAddMusic, photoCoverIndex, music, isAigc. The earlier
            // brandContentToggle/brandOrganicToggle are NOT valid (HTTP 400
            // "Unrecognized field") and the AI-disclosure field is `isAigc`,
            // NOT aiGeneratedContent. isAigc=true keeps the truth-in-compliance
            // AI disclosure intact.
            'tiktok' => ['tiktokData' => array_merge([
                'privacyOption' => 'PUBLIC_TO_EVERYONE',
                'disableComment' => false,
                'disableDuet' => false,
                'disableStitch' => false,
                'isAigc' => true, // truth in compliance — we generate with AI
            ], $this->renameKeys($ov, [
                'privacyLevel' => 'privacyOption',
                'disabledComments' => 'disableComment',
                'disabledDuet' => 'disableDuet',
                'disabledStitch' => 'disableStitch',
            ]))],
            // YouTube field names verified against the Swagger spec
            // (ScheduledPostYoutubeData): title, type, privacy, tags, category,
            // playlistId, madeForKids. notifySubscribers is NOT valid (the
            // earlier HTTP 400). title + privacy are the safe minimum.
            'youtube' => ['youtubeData' => array_merge([
                'title' => $this->youtubeTitle($caption),
                'privacy' => 'public',
            ], $this->renameKeys(
                array_intersect_key($ov, array_flip(['privacyStatus'])),
                ['privacyStatus' => 'privacy'],
            ))],
            'pinterest' => ['pinterestData' => array_filter([
                'boardId' => $ov['boardId'] ?? null,
            ], fn ($v) => $v !== null)],
            'linkedin' => ['linkedinData' => array_filter([
                // Page vs personal: Metricool selects by the connected profile;
                // a pageId override targets a Company Page.
                'pageId' => $ov['pageId'] ?? null,
            ], fn ($v) => $v !== null)],
            'facebook' => ['facebookData' => array_filter([
                'pageId' => $ov['pageId'] ?? null,
            ], fn ($v) => $v !== null)],
            'threads' => ['threadsData' => array_filter([
                'replyControl' => $ov['replyControl'] ?? null,
            ], fn ($v) => $v !== null)],
            default => [],
        };

        // Drop any `*Data` block that is an empty array — sending `[]` (a JSON
        // array) where Metricool expects an object is the START_ARRAY 400. An
        // omitted key is correct; an empty block is not.
        return array_filter($block, fn ($v) => ! (is_array($v) && $v === []));
    }

    private function youtubeTitle(string $text): string
    {
        $first = strtok($text, "\n") ?: $text;
        return mb_substr(trim($first), 0, 90);
    }

    /**
     * @param  array<string,mixed>  $src
     * @param  array<string,string>  $map  oldKey => newKey
     * @return array<string,mixed>
     */
    private function renameKeys(array $src, array $map): array
    {
        $out = [];
        foreach ($src as $k => $v) {
            $out[$map[$k] ?? $k] = $v;
        }
        return $out;
    }

    private function extractMediaRef(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_array($body)) {
            foreach (['mediaId', 'id', 'url', 'mediaUrl'] as $k) {
                if (! empty($body[$k]) && is_scalar($body[$k])) {
                    return (string) $body[$k];
                }
            }
        }
        return null;
    }

    private function extractPostId(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }
        foreach (['id', 'postId', 'uuid'] as $k) {
            if (! empty($response[$k]) && is_scalar($response[$k])) {
                return (string) $response[$k];
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            foreach (['id', 'postId', 'uuid'] as $k) {
                if (! empty($response['data'][$k]) && is_scalar($response['data'][$k])) {
                    return (string) $response['data'][$k];
                }
            }
        }
        return null;
    }

    /**
     * Find our post in Metricool's list by URL (preferred) or by the provider
     * post id captured at submit.
     *
     * @return array<string,mixed>|null
     */
    private function locatePost(mixed $body, string $knownUrl, string $providerId): ?array
    {
        $posts = [];
        if (is_array($body) && array_is_list($body)) {
            $posts = $body;
        } elseif (is_array($body)) {
            foreach (['data', 'posts', 'items', 'results'] as $key) {
                if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                    $posts = $body[$key];
                    break;
                }
            }
        }

        $needle = $knownUrl !== '' ? rtrim(strtolower(trim($knownUrl)), '/') : '';
        foreach ($posts as $p) {
            if (! is_array($p)) {
                continue;
            }
            if ($needle !== '') {
                foreach (['url', 'shareUrl', 'postUrl'] as $f) {
                    if (rtrim(strtolower((string) ($p[$f] ?? '')), '/') === $needle) {
                        return $p;
                    }
                }
            }
            if ($providerId !== '') {
                foreach (['id', 'postId', 'uuid', 'videoId'] as $f) {
                    if ((string) ($p[$f] ?? '') === $providerId) {
                        return $p;
                    }
                }
            }
        }
        return null;
    }
}
