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

    /**
     * Flip a submitted post to published/failed/pending by reading Metricool's
     * AUTHORITATIVE scheduler-status list (GET /v2/scheduler/posts).
     *
     * Why the scheduler list, not the analytics list (the 2026-06-02 fix): a
     * just-submitted post does NOT appear in /v2/analytics/posts for hours (that
     * list only carries posts the platform has begun reporting engagement on),
     * and its scheduler id is a different namespace than the analytics postId —
     * so the old analytics-list match could NEVER bridge a fresh post, stranding
     * 46 rows in `submitted` even though 43 were already PUBLISHED on-platform.
     * The scheduler list keys on row.id == our stored provider id and exposes
     * providers[].status + publicUrl the instant delivery resolves.
     *
     * Status mapping (statuses observed live + Metricool's set):
     *   PUBLISHED                    → verify publicUrl/id, then published
     *   ERROR / FAILED               → failed (carry the detail)
     *   PENDING/SCHEDULED/PUBLISHING → pending (poll again next tick)
     *
     * Falls back to the analytics list only when the scheduler row has aged out
     * of the window (covers historical rows past the lookback).
     */
    public function poll(ScheduledPost $post): PublishResult
    {
        $blogId = $post->brand?->metricool_blog_id;
        $network = $this->networkFor((string) ($post->draft?->platform ?? ''));
        $schedulerId = (string) ($post->blotato_post_id ?? '');
        if (! $blogId || $network === null) {
            return PublishResult::pending();
        }

        $platform = (string) ($post->draft?->platform ?? '');

        // 1) AUTHORITATIVE: the scheduler queue. Window covers a few days each
        //    side of now so a post scheduled slightly ahead is still found.
        if ($schedulerId !== '' && $schedulerId !== 'pending') {
            try {
                $sched = $this->client->getScheduledPosts(
                    blogId: (int) $blogId,
                    start: Carbon::now()->subDays(14)->startOfDay()->format('Y-m-d\TH:i:s'),
                    end: Carbon::now()->addDays(2)->endOfDay()->format('Y-m-d\TH:i:s'),
                );
            } catch (\Throwable $e) {
                Log::warning('MetricoolPublisher: scheduler poll failed (will retry)', [
                    'post_id' => $post->id,
                    'error' => $e->getMessage(),
                ]);
                $sched = ['found' => false, 'rows' => []];
            }

            if ($sched['found'] ?? false) {
                $provider = $this->providerStatusFor($sched['rows'], $schedulerId, $network);
                if ($provider !== null) {
                    $status = strtoupper((string) ($provider['status'] ?? ''));

                    if (in_array($status, ['ERROR', 'FAILED', 'REJECTED'], true)) {
                        $detail = (string) ($provider['detail'] ?? $provider['error'] ?? $provider['errorMessage'] ?? 'no detail');
                        return PublishResult::failed('Metricool delivery ' . $status . ': ' . substr($detail, 0, 200), $provider);
                    }

                    if ($status === 'PUBLISHED') {
                        // publicUrl is the permalink; `id` is sometimes the URL
                        // (instagram) and sometimes the platform id (facebook).
                        $url = (string) ($provider['publicUrl'] ?? $provider['url'] ?? '');
                        $rawId = (string) ($provider['id'] ?? '');
                        // If `id` is itself a URL, it's not a platform id.
                        $platformId = ($rawId !== '' && ! str_starts_with($rawId, 'http')) ? $rawId : null;
                        if ($url === '' && $platformId === null) {
                            return PublishResult::pending(raw: $provider);
                        }
                        $verdict = PostVerificationRules::verify($platform, $platformId, $url);
                        if (! $verdict['verified']) {
                            // Delivered per Metricool but not a verifiable
                            // permalink yet — keep polling rather than claim it.
                            return PublishResult::pending(raw: $provider);
                        }
                        return PublishResult::published($platformId, $url, $provider);
                    }

                    // PENDING / SCHEDULED / PUBLISHING / unknown → keep polling.
                    return PublishResult::pending(raw: $provider);
                }
                // Row not in the scheduler window → fall through to analytics.
            }
        }

        // 2) FALLBACK: the analytics list (historical rows aged out of the
        //    scheduler window, or no scheduler id). Match robustly by the
        //    captured permalink — the same canonical strategy the metrics
        //    collector uses — never by the scheduler-id (wrong namespace).
        $knownUrl = (string) ($post->platform_post_url ?? '');
        if ($knownUrl === '') {
            // No permalink + not in scheduler → nothing to verify against yet.
            return PublishResult::pending();
        }

        try {
            $result = $this->client->postAnalytics(
                blogId: (int) $blogId,
                from: Carbon::now()->subDays(7)->startOfDay()->format('Y-m-d\TH:i:s'),
                to: Carbon::now()->endOfDay()->format('Y-m-d\TH:i:s'),
                network: $network,
            );
        } catch (\Throwable $e) {
            Log::warning('MetricoolPublisher: analytics poll fallback failed (will retry)', [
                'post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
            return PublishResult::pending();
        }

        if (! ($result['found'] ?? false)) {
            return PublishResult::pending();
        }

        $match = $this->locatePost($result['body'], $knownUrl, '');
        if ($match === null) {
            return PublishResult::pending();
        }

        $url = (string) ($match['url'] ?? $match['shareUrl'] ?? $match['postUrl'] ?? $match['watchUrl'] ?? $match['permalink'] ?? '');
        $platformId = isset($match['postId']) ? (string) $match['postId']
            : (isset($match['videoId']) ? (string) $match['videoId'] : null);

        $verdict = PostVerificationRules::verify($platform, $platformId, $url);
        if (! $verdict['verified']) {
            return PublishResult::pending(raw: $match);
        }

        return PublishResult::published($platformId, $url, $match);
    }

    /**
     * Find OUR scheduler row (by id) in the scheduler list, then the provider
     * entry for OUR network. Returns the provider sub-object or null.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>|null
     */
    private function providerStatusFor(array $rows, string $schedulerId, string $network): ?array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowId = (string) ($row['id'] ?? $row['uuid'] ?? '');
            if ($rowId !== $schedulerId) {
                continue;
            }
            $providers = $row['providers'] ?? [];
            if (! is_array($providers)) {
                return null;
            }
            foreach ($providers as $pr) {
                if (is_array($pr) && strtolower((string) ($pr['network'] ?? '')) === strtolower($network)) {
                    return $pr;
                }
            }
            // Row matched but no provider for our network — single-network rows
            // sometimes omit the network key; return the sole provider.
            if (count($providers) === 1 && is_array($providers[0])) {
                return $providers[0];
            }
            return null;
        }
        return null;
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
                'boardId' => $ov['boardId'] ?? null, // valid per Swagger ScheduledPostPinterestData
            ], fn ($v) => $v !== null)],
            // Facebook & LinkedIn: Metricool selects the Page / Company-page by
            // the brand's CONNECTED profile, NOT a body field. Per the Swagger,
            // ScheduledPostFacebookData (boost*, type, title) and
            // ScheduledPostLinkedinData (documentTitle, publishImagesAsPDF,
            // previewIncluded, type, poll) have NO `pageId` — sending one yields
            // HTTP 400 "Unrecognized field 'pageId'". So we send NO targeting
            // block; the empty array is dropped by the array_filter below. (A
            // leftover target_overrides.pageId from the Blotato era is ignored
            // on purpose — it is not a Metricool field.)
            'linkedin' => ['linkedinData' => []],
            'facebook' => ['facebookData' => []],
            'threads' => ['threadsData' => array_filter([
                'replyControl' => $ov['replyControl'] ?? null, // valid per Swagger ScheduledPostThreadsData
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
