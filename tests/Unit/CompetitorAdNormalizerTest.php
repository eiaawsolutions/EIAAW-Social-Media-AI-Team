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

    // ── LinkedIn 404 / error-page guard (defence-in-depth) ────────────────

    public function test_looks_like_error_page_detects_the_404_shell(): void
    {
        // The exact junk that got stored in the incident: multilingual
        // "page not found" bodies + feed links with the 404_page trk marker.
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage('Page not found'));
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage('لم يتم العثور على الصفحة'));
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage('Seite nicht gefunden'));
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage('Página no encontrada'));
        // Empty body is junk.
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage(''));
        // The language-agnostic signal: a feed link with the 404 tracking marker.
        $this->assertTrue(CompetitorAdNormalizer::looksLikeErrorPage(
            'Some body', 'https://www.linkedin.com/feed/?trk=404_page'
        ));
    }

    public function test_looks_like_error_page_passes_real_competitor_copy(): void
    {
        $this->assertFalse(CompetitorAdNormalizer::looksLikeErrorPage(
            'SleekFlow — Boost lead generation with AI chatbots that qualify and book 24/7.',
            'https://www.linkedin.com/company/sleekflow',
            'https://www.linkedin.com/company/sleekflow',
        ));
    }

    public function test_from_linkedin_rejects_an_error_page_row(): void
    {
        $this->expectException(\RuntimeException::class);

        CompetitorAdNormalizer::fromLinkedin(
            row: [
                'body' => 'Page not found',
                'landing_url' => 'https://www.linkedin.com/feed/?trk=404_page',
                'ad_url' => '',
                'ad_id' => 'x',
            ],
            brandId: 7,
            workspaceId: 3,
            competitorHandle: 'dah-reply-ai',
            competitorLabel: 'Dah Reply',
            retentionDays: 30,
        );
    }

    public function test_from_linkedin_clamps_long_url_to_varchar_limit(): void
    {
        // A long LinkedIn post permalink (activity id + tracking params) must not
        // overflow the varchar(255) source_url/landing_url columns and drop the row.
        $longUrl = 'https://www.linkedin.com/posts/sleekflow_'.str_repeat('a', 400);

        $payload = CompetitorAdNormalizer::fromLinkedin(
            row: [
                'body' => 'SleekFlow — real competitor messaging here.',
                'ad_url' => $longUrl,
                'landing_url' => $longUrl,
                'ad_id' => str_repeat('x', 400),
                'cta_text' => str_repeat('c', 400),
            ],
            brandId: 7, workspaceId: 3, competitorHandle: 'sleekflow', competitorLabel: 'SleekFlow', retentionDays: 30,
        );

        $this->assertLessThanOrEqual(255, mb_strlen((string) $payload['source_url']));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $payload['landing_url']));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $payload['source_ad_id']));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $payload['cta']));
    }

    public function test_from_linkedin_sanitises_malformed_utf8_body(): void
    {
        // A body with an invalid byte sequence must not crash and must come out
        // as valid UTF-8 (the injection grader threw "Malformed UTF-8" on this).
        $badBody = "Real competitor copy \xB1\x31 with a bad byte";

        $payload = CompetitorAdNormalizer::fromLinkedin(
            row: [
                'body' => $badBody,
                'ad_url' => 'https://www.linkedin.com/posts/acme_activity-1',
                'ad_id' => 'a1',
            ],
            brandId: 7, workspaceId: 3, competitorHandle: 'acme', competitorLabel: 'Acme', retentionDays: 30,
        );

        $this->assertTrue(mb_check_encoding((string) $payload['body'], 'UTF-8'));
        $this->assertStringContainsString('Real competitor copy', (string) $payload['body']);
    }

    public function test_from_linkedin_accepts_real_search_derived_row(): void
    {
        // The shape the rewritten client now emits (title+snippet as body,
        // the LinkedIn URL as ad_url/landing_url).
        $payload = CompetitorAdNormalizer::fromLinkedin(
            row: [
                'body' => "SleekFlow - AI Suite for Revenue-Driving Conversations\nTeams of AI agents that qualify, sell, book, and support 24/7.",
                'ad_url' => 'https://www.linkedin.com/posts/sleekflow_activity-123',
                'landing_url' => 'https://www.linkedin.com/posts/sleekflow_activity-123',
                'ad_id' => 'abc123',
            ],
            brandId: 7,
            workspaceId: 3,
            competitorHandle: 'sleekflow',
            competitorLabel: 'SleekFlow',
            retentionDays: 30,
        );

        $this->assertSame('linkedin', $payload['platform']);
        $this->assertStringContainsString('Revenue-Driving Conversations', $payload['body']);
        $this->assertSame('https://www.linkedin.com/posts/sleekflow_activity-123', $payload['source_url']);
        $this->assertSame(40, strlen($payload['dedup_hash']));
    }
}
