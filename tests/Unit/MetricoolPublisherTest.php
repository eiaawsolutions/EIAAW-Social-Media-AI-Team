<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Metricool\MetricoolClient;
use App\Services\Publishing\MetricoolPublisher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetricoolPublisher — submit() builds the right /v2/scheduler/posts body and
 * targets by brand blogId (NOT a per-account id). DB-free: in-memory models +
 * Http::fake. Verifies the per-network *Data mapping and the media-normalise
 * step that the publishing-parity audit flagged as ADAPT work.
 */
class MetricoolPublisherTest extends TestCase
{
    private function client(): MetricoolClient
    {
        return new MetricoolClient(
            apiToken: 'mc_test',
            userId: 4872275,
            baseUrl: 'https://app.metricool.com/api',
            timeout: 30,
        );
    }

    private function makePost(
        string $platform,
        array $targetOverrides = [],
        string $blogId = '6322515',
        int $brandId = 10,
        int $workspaceId = 1,
    ): ScheduledPost {
        $ws = new Workspace();
        $ws->id = $workspaceId;
        $ws->settings = ['timezone' => 'Asia/Kuala_Lumpur'];

        $brand = new Brand();
        $brand->id = $brandId;
        $brand->metricool_blog_id = $blogId;
        $brand->setRelation('workspace', $ws);

        $draft = new Draft();
        $draft->platform = $platform;

        $conn = new PlatformConnection();
        $conn->target_overrides = $targetOverrides;

        $post = new ScheduledPost();
        $post->id = 700;
        $post->brand_id = $brandId;
        $post->setRelation('brand', $brand);
        $post->setRelation('draft', $draft);
        $post->setRelation('platformConnection', $conn);

        return $post;
    }

    public function test_submit_targets_brand_blog_id_and_returns_submitted(): void
    {
        // The /actions/normalize/* endpoint returns text/plain (the normalised
        // URL as a bare string), NOT JSON — see MetricoolClient::normalizeMedia.
        Http::fake([
            'app.metricool.com/api/actions/normalize/*' => Http::response(
                'https://media.metricool.com/img.jpg', 200, ['Content-Type' => 'text/plain']
            ),
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-700'], 200),
        ]);

        $result = (new MetricoolPublisher($this->client()))->submit(
            $this->makePost('instagram'),
            'caption text #tag',
            ['https://r2.example/img.jpg'],
        );

        $this->assertSame('submitted', $result->state);
        $this->assertSame('sched-700', $result->providerPostId);

        // The scheduler call must be scoped to blogId 6322515 and carry the
        // normalised media URL (the plain-text body) + provider object.
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/v2/scheduler/posts')) {
                return false;
            }
            return str_contains($r->url(), 'blogId=6322515')
                && $r['text'] === 'caption text #tag'
                && $r['autoPublish'] === true
                && $r['providers'][0]['network'] === 'instagram'
                && $r['media'] === ['https://media.metricool.com/img.jpg'];
        });
    }

    /**
     * REGRESSION (root cause of the 2026-06-01 "Media normalize failed … HTTP
     * 406" outage): the normalize endpoint serves text/plain, so the client must
     * NOT demand `Accept: application/json` — that makes Tomcat reject with 406.
     * This locks the request to send a non-JSON Accept header.
     */
    public function test_normalize_request_does_not_demand_json_accept(): void
    {
        Http::fake([
            'app.metricool.com/api/actions/normalize/*' => Http::response(
                'https://media.metricool.com/ok.jpg', 200, ['Content-Type' => 'text/plain']
            ),
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-acc'], 200),
        ]);

        (new MetricoolPublisher($this->client()))
            ->submit($this->makePost('instagram'), 'cap', ['https://r2.example/img.jpg']);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/actions/normalize/')) {
                return false;
            }
            // The Accept header must permit text/plain (i.e. it is NOT pinned to
            // application/json). `*\/*` satisfies the live server.
            $accept = strtolower(implode(',', $r->header('Accept')));
            return $accept !== 'application/json' && ($accept === '' || str_contains($accept, '*/*'));
        });
    }

    /**
     * A 2xx response with an empty body is NOT a usable normalised URL — the
     * publisher must hard-fail rather than schedule a media-less post (no
     * half-posts contract).
     */
    public function test_submit_fails_when_normalize_returns_empty_body(): void
    {
        Http::fake([
            'app.metricool.com/api/actions/normalize/*' => Http::response('', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = (new MetricoolPublisher($this->client()))
            ->submit($this->makePost('instagram'), 'cap', ['https://r2.example/img.jpg']);

        $this->assertSame('failed', $result->state);
        $this->assertStringContainsString('normalize', (string) $result->error);
    }

    public function test_submit_builds_tiktok_data_with_ai_flag_and_privacy(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-tt'], 200),
        ]);

        // No media for this assertion — focus on tiktokData.
        (new MetricoolPublisher($this->client()))->submit($this->makePost('tiktok'), 'tt caption', []);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/v2/scheduler/posts')) {
                return false;
            }
            $tt = $r['tiktokData'] ?? null;
            // Field names per Metricool's Swagger ScheduledPostTikTokData:
            // privacyOption + isAigc (the AI-disclosure field). The invalid
            // brandContentToggle/brandOrganicToggle/aiGeneratedContent must NOT
            // be sent (each triggers HTTP 400 "Unrecognized field").
            return is_array($tt)
                && $tt['privacyOption'] === 'PUBLIC_TO_EVERYONE'
                && ($tt['isAigc'] ?? null) === true
                && ! array_key_exists('aiGeneratedContent', $tt)
                && ! array_key_exists('brandContentToggle', $tt)
                && ! array_key_exists('brandOrganicToggle', $tt);
        });
    }

    public function test_submit_maps_tiktok_override_privacy_level_to_privacy_option(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-tt2'], 200),
        ]);

        // Operator override uses the Blotato-era key `privacyLevel`; the
        // publisher must rename it to Metricool's `privacyOption`.
        (new MetricoolPublisher($this->client()))
            ->submit($this->makePost('tiktok', ['privacyLevel' => 'SELF_ONLY']), 'cap', []);

        Http::assertSent(function ($r) {
            $tt = $r['tiktokData'] ?? [];
            return ($tt['privacyOption'] ?? null) === 'SELF_ONLY';
        });
    }

    /**
     * Tenant isolation at the publish boundary: the SAME publisher, given posts
     * from two different brands/workspaces, routes each to its OWN brand's
     * blogId. This is the load-bearing guarantee behind "HQ posts only land on
     * HQ platforms; a client's posts only land on theirs" — MetricoolPublisher
     * derives the target purely from $post->brand->metricool_blog_id, so a post
     * can only ever reach its own brand's connected account.
     */
    public function test_submit_routes_each_brand_to_its_own_blog_id(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-x'], 200),
        ]);

        $publisher = new MetricoolPublisher($this->client());

        // HQ post (brand 10 / ws 1 / blogId 6322515)
        $publisher->submit($this->makePost('instagram', blogId: '6322515', brandId: 10, workspaceId: 1), 'hq caption', []);
        // Client post (brand 88 / ws 3 / blogId 6325160)
        $publisher->submit($this->makePost('instagram', blogId: '6325160', brandId: 88, workspaceId: 3), 'client caption', []);

        // The HQ caption must ONLY ever have gone to the HQ blogId, and the
        // client caption ONLY to the client blogId. No crossing.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/scheduler/posts')
            && str_contains($r->url(), 'blogId=6322515')
            && $r['text'] === 'hq caption');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/scheduler/posts')
            && str_contains($r->url(), 'blogId=6325160')
            && $r['text'] === 'client caption');
        // The cross combinations must NEVER have been sent.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'blogId=6325160')
            && ($r['text'] ?? null) === 'hq caption');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'blogId=6322515')
            && ($r['text'] ?? null) === 'client caption');
    }

    /**
     * REGRESSION (the 2026-06-01 START_ARRAY deserialization 400): a network
     * with no per-network overrides must OMIT its `*Data` key entirely. Sending
     * an empty `linkedinData: []` serialises to a JSON array, which Metricool
     * rejects with "Cannot deserialize …LinkedinData out of START_ARRAY".
     */
    public function test_submit_omits_empty_per_network_data_block(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-li'], 200),
        ]);

        // LinkedIn with no target overrides → linkedinData would be []; it must
        // not be sent at all.
        (new MetricoolPublisher($this->client()))->submit($this->makePost('linkedin'), 'li caption', []);

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/v2/scheduler/posts')) {
                return false;
            }
            // The key must be absent — NOT present as an empty array.
            return ! array_key_exists('linkedinData', (array) $r->data());
        });
    }

    /**
     * When a LinkedIn pageId override IS present, linkedinData is sent as a
     * populated object (so Page targeting still works).
     */
    public function test_submit_sends_linkedin_data_when_page_id_override_present(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-li2'], 200),
        ]);

        (new MetricoolPublisher($this->client()))
            ->submit($this->makePost('linkedin', ['pageId' => '12345']), 'li', []);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/scheduler/posts')
            && ($r['linkedinData']['pageId'] ?? null) === '12345');
    }

    /**
     * YouTube must send only the recognised fields (title + privacy) and NOT the
     * unrecognised notifySubscribers/madeForKids that triggered HTTP 400
     * "Unrecognized field".
     */
    public function test_submit_youtube_data_excludes_unrecognised_fields(): void
    {
        Http::fake([
            'app.metricool.com/api/v2/scheduler/posts*' => Http::response(['id' => 'sched-yt'], 200),
        ]);

        (new MetricoolPublisher($this->client()))->submit($this->makePost('youtube'), 'My YouTube Title', []);

        Http::assertSent(function ($r) {
            $yt = $r['youtubeData'] ?? null;
            return is_array($yt)
                && isset($yt['title'], $yt['privacy'])
                && ! array_key_exists('notifySubscribers', $yt)
                && ! array_key_exists('madeForKids', $yt);
        });
    }

    public function test_submit_fails_when_brand_has_no_blog_id(): void
    {
        $post = $this->makePost('instagram');
        $post->brand->metricool_blog_id = null;

        $result = (new MetricoolPublisher($this->client()))->submit($post, 'cap', []);

        $this->assertSame('failed', $result->state);
        $this->assertStringContainsString('metricool_blog_id', (string) $result->error);
    }

    public function test_submit_fails_when_media_normalize_fails(): void
    {
        Http::fake([
            'app.metricool.com/api/actions/normalize/*' => Http::response(['error' => 'bad url'], 422),
        ]);

        $result = (new MetricoolPublisher($this->client()))
            ->submit($this->makePost('instagram'), 'cap', ['https://r2.example/broken.jpg']);

        $this->assertSame('failed', $result->state);
        $this->assertStringContainsString('normalize', (string) $result->error);
    }
}
