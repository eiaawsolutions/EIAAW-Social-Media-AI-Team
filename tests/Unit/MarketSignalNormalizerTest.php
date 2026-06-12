<?php

namespace Tests\Unit;

use App\Services\Intel\MarketSignalNormalizer;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The verification gate is the truthfulness contract for market intel. These
 * are pure-function tests (no DB) — they lock the "discard anything without a
 * real evidence URL / too stale / contentless" behaviour.
 */
class MarketSignalNormalizerTest extends TestCase
{
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Specialty coffee demand climbs in Southeast Asia',
            'url' => 'https://example.com/coffee-trends-2026',
            'snippet' => 'New data shows rising specialty coffee consumption among urban professionals.',
            'published_at' => Carbon::now()->subDays(3)->toIso8601String(),
        ], $overrides);
    }

    public function test_valid_result_is_normalised_with_dedup_hash_and_fetched_at(): void
    {
        $now = Carbon::parse('2026-06-12T00:00:00Z');

        $payload = MarketSignalNormalizer::fromSearchResult(
            row: $this->validRow(['published_at' => $now->copy()->subDays(2)->toIso8601String()]),
            brandId: 7,
            workspaceId: 3,
            query: 'coffee industry trends 2026 Malaysia',
            signalClass: MarketSignalNormalizer::CLASS_INDUSTRY_TREND,
            recencyDays: 21,
            now: $now,
        );

        $this->assertNotNull($payload);
        $this->assertSame(7, $payload['brand_id']);
        $this->assertSame(3, $payload['workspace_id']);
        $this->assertSame(MarketSignalNormalizer::CLASS_INDUSTRY_TREND, $payload['signal_class']);
        $this->assertSame('https://example.com/coffee-trends-2026', $payload['source_url']);
        $this->assertSame(40, strlen($payload['dedup_hash']), 'dedup_hash should be sha1 (40 hex chars)');
        $this->assertNotNull($payload['fetched_at']);
        $this->assertNotNull($payload['observed_at']);
        $this->assertNotNull($payload['expires_at']);
    }

    public function test_result_without_url_is_discarded(): void
    {
        $payload = MarketSignalNormalizer::fromSearchResult(
            row: $this->validRow(['url' => '']),
            brandId: 7,
            workspaceId: 3,
            query: 'q',
            signalClass: MarketSignalNormalizer::CLASS_MARKET_NEWS,
            recencyDays: 21,
        );

        $this->assertNull($payload, 'A signal with no evidence URL must be discarded.');
    }

    public function test_result_with_non_http_url_is_discarded(): void
    {
        foreach (['ftp://example.com/x', 'javascript:alert(1)', 'not-a-url', 'mailto:a@b.com'] as $bad) {
            $payload = MarketSignalNormalizer::fromSearchResult(
                row: $this->validRow(['url' => $bad]),
                brandId: 1,
                workspaceId: 1,
                query: 'q',
                signalClass: MarketSignalNormalizer::CLASS_MARKET_NEWS,
                recencyDays: 21,
            );
            $this->assertNull($payload, "Non-http url '{$bad}' must be discarded.");
        }
    }

    public function test_result_with_no_title_is_discarded(): void
    {
        $payload = MarketSignalNormalizer::fromSearchResult(
            row: $this->validRow(['title' => '']),
            brandId: 1,
            workspaceId: 1,
            query: 'q',
            signalClass: MarketSignalNormalizer::CLASS_MARKET_NEWS,
            recencyDays: 21,
        );

        $this->assertNull($payload, 'A signal must at least be nameable (have a title).');
    }

    public function test_stale_published_date_is_discarded(): void
    {
        $now = Carbon::parse('2026-06-12T00:00:00Z');

        $payload = MarketSignalNormalizer::fromSearchResult(
            row: $this->validRow(['published_at' => $now->copy()->subDays(60)->toIso8601String()]),
            brandId: 1,
            workspaceId: 1,
            query: 'q',
            signalClass: MarketSignalNormalizer::CLASS_INDUSTRY_TREND,
            recencyDays: 21,
            now: $now,
        );

        $this->assertNull($payload, 'A 60-day-old item with a 21-day recency ceiling must be discarded.');
    }

    public function test_missing_published_date_is_admitted(): void
    {
        // We can't always know the publish date; search recency is the proxy.
        // A verified URL + title with no date is admitted (not rejected).
        $payload = MarketSignalNormalizer::fromSearchResult(
            row: $this->validRow(['published_at' => null]),
            brandId: 1,
            workspaceId: 1,
            query: 'q',
            signalClass: MarketSignalNormalizer::CLASS_MARKET_NEWS,
            recencyDays: 21,
        );

        $this->assertNotNull($payload);
    }

    public function test_dedup_hash_stable_across_runs_and_changes_with_url(): void
    {
        $a = MarketSignalNormalizer::fromSearchResult($this->validRow(), 1, 1, 'q', 'market_news', 21);
        $b = MarketSignalNormalizer::fromSearchResult($this->validRow(), 1, 1, 'q', 'market_news', 21);
        $this->assertSame($a['dedup_hash'], $b['dedup_hash']);

        $c = MarketSignalNormalizer::fromSearchResult(
            $this->validRow(['url' => 'https://example.com/DIFFERENT']),
            1, 1, 'q', 'market_news', 21,
        );
        $this->assertNotSame($a['dedup_hash'], $c['dedup_hash']);
    }

    public function test_canonical_url_normalises_trailing_slash_and_case(): void
    {
        $this->assertSame(
            MarketSignalNormalizer::canonicalUrl('https://Example.com/Path/'),
            MarketSignalNormalizer::canonicalUrl('https://example.com/Path'),
        );
    }

    public function test_is_http_url_guard(): void
    {
        $this->assertTrue(MarketSignalNormalizer::isHttpUrl('https://a.com'));
        $this->assertTrue(MarketSignalNormalizer::isHttpUrl('http://a.com/x?y=1'));
        $this->assertFalse(MarketSignalNormalizer::isHttpUrl(''));
        $this->assertFalse(MarketSignalNormalizer::isHttpUrl('ftp://a.com'));
        $this->assertFalse(MarketSignalNormalizer::isHttpUrl('https://'));
    }
}
