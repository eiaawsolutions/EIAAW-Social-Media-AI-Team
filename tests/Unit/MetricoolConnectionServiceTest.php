<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Services\Metricool\MetricoolClient;
use App\Services\Metricool\MetricoolConnectionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MetricoolConnectionService::detect() — maps the live /admin/profile shape
 * onto our connected-network list. DB-free: Http::fake + in-memory Brand.
 * The fixture is the REAL profile shape captured live 2026-05-30 (HQ brand):
 * per-network keys hold the handle when connected, null when not.
 */
class MetricoolConnectionServiceTest extends TestCase
{
    private function client(): MetricoolClient
    {
        return new MetricoolClient('mc_test', 4872275, 'https://app.metricool.com/api', 30);
    }

    private function brand(?string $blogId): Brand
    {
        $b = new Brand();
        $b->id = 10;
        $b->metricool_blog_id = $blogId;
        return $b;
    }

    /** The real /admin/profile shape (trimmed to relevant fields). */
    private function fakeProfile(): array
    {
        return [
            'id' => 6322515,
            'label' => 'eiaawsolutions',
            'twitter' => null,                                  // not connected
            'facebook' => 'EIAAW Solutions',
            'facebookPageId' => '1179788141875323',
            'instagram' => 'eiaawsolutions',
            'linkedinCompany' => 'urn:li:person:c9xgTD3co8',
            'youtube' => 'UCeBXTSxwEca5xrX1PC9YTvQ',
            'pinterest' => null,                                // not connected
            'tiktok' => 'eiaawsolutions',
            'threads' => 'eiaawsolutions',
            'bluesky' => null,                                  // not connected
        ];
    }

    public function test_detect_maps_connected_networks_from_live_profile_shape(): void
    {
        Http::fake([
            'app.metricool.com/api/admin/profile*' => Http::response($this->fakeProfile(), 200),
        ]);

        $result = (new MetricoolConnectionService($this->client()))->detect($this->brand('6322515'));

        $this->assertTrue($result['found']);
        $nets = $result['networks'];

        // Connected networks present with their handles.
        $this->assertSame('eiaawsolutions', $nets['instagram']);
        $this->assertSame('1179788141875323', $nets['facebook']);   // facebookPageId wins
        $this->assertSame('urn:li:person:c9xgTD3co8', $nets['linkedin']); // linkedinCompany
        $this->assertSame('eiaawsolutions', $nets['tiktok']);
        $this->assertSame('eiaawsolutions', $nets['threads']);
        $this->assertSame('UCeBXTSxwEca5xrX1PC9YTvQ', $nets['youtube']);

        // Not-connected networks absent (null → not included, never fabricated).
        $this->assertArrayNotHasKey('x', $nets);          // twitter null
        $this->assertArrayNotHasKey('pinterest', $nets);
        $this->assertArrayNotHasKey('bluesky', $nets);
    }

    public function test_detect_scopes_profile_call_to_blog_id(): void
    {
        Http::fake([
            'app.metricool.com/api/admin/profile*' => Http::response($this->fakeProfile(), 200),
        ]);

        (new MetricoolConnectionService($this->client()))->detect($this->brand('6322515'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/admin/profile')
            && str_contains($r->url(), 'blogId=6322515')
            && str_contains($r->url(), 'userId=4872275'));
    }

    public function test_detect_returns_not_found_when_brand_unmapped(): void
    {
        Http::fake(); // must not call the API at all

        $result = (new MetricoolConnectionService($this->client()))->detect($this->brand(null));

        $this->assertFalse($result['found']);
        $this->assertSame([], $result['networks']);
        Http::assertNothingSent();
    }

    public function test_detect_returns_not_found_on_404(): void
    {
        Http::fake([
            'app.metricool.com/api/admin/profile*' => Http::response(['message' => 'not found'], 404),
        ]);

        $result = (new MetricoolConnectionService($this->client()))->detect($this->brand('999'));

        $this->assertFalse($result['found']);
        $this->assertSame([], $result['networks']);
    }
}
