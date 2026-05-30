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

    private function makePost(string $platform, array $targetOverrides = []): ScheduledPost
    {
        $ws = new Workspace();
        $ws->id = 1;
        $ws->settings = ['timezone' => 'Asia/Kuala_Lumpur'];

        $brand = new Brand();
        $brand->id = 10;
        $brand->metricool_blog_id = '6322515';
        $brand->setRelation('workspace', $ws);

        $draft = new Draft();
        $draft->platform = $platform;

        $conn = new PlatformConnection();
        $conn->target_overrides = $targetOverrides;

        $post = new ScheduledPost();
        $post->id = 700;
        $post->brand_id = 10;
        $post->setRelation('brand', $brand);
        $post->setRelation('draft', $draft);
        $post->setRelation('platformConnection', $conn);

        return $post;
    }

    public function test_submit_targets_brand_blog_id_and_returns_submitted(): void
    {
        Http::fake([
            'app.metricool.com/api/actions/normalize/*' => Http::response(['mediaId' => 'mc-media-1'], 200),
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
        // normalised media + provider object.
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), '/v2/scheduler/posts')) {
                return false;
            }
            return str_contains($r->url(), 'blogId=6322515')
                && $r['text'] === 'caption text #tag'
                && $r['autoPublish'] === true
                && $r['providers'][0]['network'] === 'instagram'
                && $r['media'] === ['mc-media-1'];
        });
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
            return is_array($tt)
                && $tt['privacyOption'] === 'PUBLIC_TO_EVERYONE'
                && $tt['aiGeneratedContent'] === true;
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
