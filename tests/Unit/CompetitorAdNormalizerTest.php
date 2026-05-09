<?php

namespace Tests\Unit;

use App\Services\Intel\CompetitorAdNormalizer;
use Tests\TestCase;

class CompetitorAdNormalizerTest extends TestCase
{
    public function test_meta_row_is_normalised_with_dedup_hash(): void
    {
        $row = [
            'id' => 'ad_123',
            'ad_creative_bodies' => ['Buy our coffee — best in town.'],
            'ad_creative_link_titles' => ['Acme Coffee'],
            'ad_creative_link_captions' => ['Learn more'],
            'ad_creation_time' => '2026-04-15T08:00:00+0000',
            'ad_snapshot_url' => 'https://www.facebook.com/ads/library/?id=123',
            'page_name' => 'Acme',
            'publisher_platforms' => ['facebook', 'instagram'],
        ];

        $payload = CompetitorAdNormalizer::fromMeta(
            row: $row,
            brandId: 7,
            workspaceId: 3,
            competitorHandle: '987654321',
            competitorLabel: 'Acme Corp',
            retentionDays: 30,
        );

        $this->assertSame('meta', $payload['platform']);
        $this->assertSame(7, $payload['brand_id']);
        $this->assertSame(3, $payload['workspace_id']);
        $this->assertSame('987654321', $payload['competitor_handle']);
        $this->assertSame('Acme Corp', $payload['competitor_label']);
        $this->assertSame('ad_123', $payload['source_ad_id']);
        $this->assertStringContainsString('Buy our coffee', $payload['body']);
        $this->assertSame('Learn more', $payload['cta']);
        $this->assertEquals(['facebook', 'instagram'], $payload['platforms_seen_on']);
        $this->assertSame(40, strlen($payload['dedup_hash']), 'dedup_hash should be sha1 (40 hex chars)');
        $this->assertNotNull($payload['observed_at']);
        $this->assertNotNull($payload['expires_at']);
    }

    public function test_meta_dedup_hash_stable_across_runs(): void
    {
        $row = [
            'id' => 'ad_456',
            'ad_creative_bodies' => ['Same body'],
            'page_name' => 'Acme',
        ];

        $a = CompetitorAdNormalizer::fromMeta($row, 7, 3, 'page_id', 'Acme', 30);
        $b = CompetitorAdNormalizer::fromMeta($row, 7, 3, 'page_id', 'Acme', 30);

        $this->assertSame($a['dedup_hash'], $b['dedup_hash']);
    }

    public function test_meta_dedup_hash_changes_when_body_changes(): void
    {
        $rowA = ['id' => 'x', 'ad_creative_bodies' => ['Body A']];
        $rowB = ['id' => 'x', 'ad_creative_bodies' => ['Body B']];

        $a = CompetitorAdNormalizer::fromMeta($rowA, 7, 3, 'h', null, 30);
        $b = CompetitorAdNormalizer::fromMeta($rowB, 7, 3, 'h', null, 30);

        $this->assertNotSame($a['dedup_hash'], $b['dedup_hash']);
    }

    public function test_linkedin_row_is_normalised(): void
    {
        $row = [
            'body' => 'Join our webinar on AI for sales',
            'image_url' => 'https://media.licdn.com/dms/image/abc.jpg',
            'ad_url' => 'https://www.linkedin.com/ad-library/ad/123',
            'ad_id' => 'li_456',
            'cta_text' => 'Register',
            'landing_url' => 'https://acme.com/webinar',
            'first_seen_date' => '2026-05-01',
        ];

        $payload = CompetitorAdNormalizer::fromLinkedin(
            row: $row,
            brandId: 7,
            workspaceId: 3,
            competitorHandle: 'acme-corp',
            competitorLabel: 'Acme Corp',
            retentionDays: 30,
        );

        $this->assertSame('linkedin', $payload['platform']);
        $this->assertSame('li_456', $payload['source_ad_id']);
        $this->assertSame('Register', $payload['cta']);
        $this->assertSame('https://acme.com/webinar', $payload['landing_url']);
        $this->assertEquals(['https://media.licdn.com/dms/image/abc.jpg'], $payload['asset_urls']);
        $this->assertEquals(['linkedin'], $payload['platforms_seen_on']);
    }

    public function test_meta_handles_empty_body_with_titles_fallback(): void
    {
        $row = [
            'id' => 'ad_789',
            'ad_creative_bodies' => [],
            'ad_creative_link_titles' => ['Headline only'],
            'ad_creative_link_descriptions' => ['Some descriptive text'],
        ];

        $payload = CompetitorAdNormalizer::fromMeta($row, 1, 1, 'h', null, 30);

        $this->assertNotNull($payload['body']);
        $this->assertStringContainsString('Headline only', $payload['body']);
    }
}
