<?php

namespace App\Services\Intel;

use Carbon\Carbon;

/**
 * Normalises raw Firecrawl search results into the market_signals schema AND
 * is the truthfulness-contract verification gate. Pure functions — no I/O.
 *
 * The gate (verify()) is the non-negotiable rule from the global lead-gen /
 * truthfulness contract applied to market intelligence: a signal is admitted
 * ONLY if it carries verifiable evidence — a real http(s) source URL — plus a
 * fetched_at, non-empty text, and (for trend-class signals) acceptable
 * recency. Anything weaker is DISCARDED (fromSearchResult returns null), never
 * coerced into a row. Fewer verified signals > many unverified.
 *
 * dedup_hash: sha1 over canonical url + normalized title so repeated weekly
 * runs upsert the same item instead of duplicating it (same mechanism as
 * CompetitorAdNormalizer).
 */
final class MarketSignalNormalizer
{
    public const CLASS_MARKET_NEWS = 'market_news';
    public const CLASS_INDUSTRY_TREND = 'industry_trend';
    public const CLASS_SEASONAL_TOPICAL = 'seasonal_topical';

    /**
     * Convert one Firecrawl search row into a market_signals payload, or null
     * if it fails the verification gate.
     *
     * @param  array<string,mixed>  $row  {title, url, snippet, published_at?}
     * @return array<string,mixed>|null   Shape ready for MarketSignal::create, or null if rejected
     */
    public static function fromSearchResult(
        array $row,
        int $brandId,
        int $workspaceId,
        string $query,
        string $signalClass,
        int $recencyDays,
        ?int $pipelineRunId = null,
        ?Carbon $now = null,
    ): ?array {
        $now ??= Carbon::now();

        $title = trim((string) ($row['title'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));
        $snippet = trim((string) ($row['snippet'] ?? ''));
        $publishedAt = self::parseTime($row['published_at'] ?? null);

        $candidate = [
            'title' => $title,
            'url' => $url,
            'snippet' => $snippet,
            'published_at' => $publishedAt,
            'signal_class' => $signalClass,
            'recency_days' => $recencyDays,
            'now' => $now,
        ];

        if (! self::verify($candidate)) {
            return null;
        }

        $dedupSrc = self::canonicalUrl($url).'|'.mb_strtolower($title);

        return [
            'brand_id' => $brandId,
            'workspace_id' => $workspaceId,
            'signal_class' => $signalClass,
            'query' => mb_substr($query, 0, 255),
            'title' => mb_substr($title, 0, 500),
            'snippet' => $snippet !== '' ? $snippet : null,
            'source_url' => $url,
            'published_at' => $publishedAt,
            'fetched_at' => $now,
            'dedup_hash' => sha1($dedupSrc),
            'observed_at' => $now,
            'expires_at' => $now->copy()->addDays(max(1, $recencyDays)),
            'pipeline_run_id' => $pipelineRunId,
        ];
    }

    /**
     * The verification gate, extracted for isolated unit testing. Returns true
     * only when the candidate carries verifiable evidence and is fresh enough.
     * Fails aggressively — when in doubt, discard.
     *
     * @param  array<string,mixed>  $c  {title, url, snippet, published_at:?Carbon, signal_class, recency_days, now:Carbon}
     */
    public static function verify(array $c): bool
    {
        // 1. Evidence link is non-negotiable — a valid http(s) URL or nothing.
        $url = trim((string) ($c['url'] ?? ''));
        if (! self::isHttpUrl($url)) {
            return false;
        }

        // 2. Must carry actual content (a bare URL with no title/snippet is noise).
        $title = trim((string) ($c['title'] ?? ''));
        $snippet = trim((string) ($c['snippet'] ?? ''));
        if ($title === '' && $snippet === '') {
            return false;
        }
        if ($title === '') {
            return false; // a signal must at least be nameable
        }

        // 3. Recency ceiling for trend-class signals. A "trend" that's months
        //    old is not a trend. market_news/industry_trend/seasonal_topical
        //    all carry a recency ceiling; when we have no published_at we admit
        //    it (search recency is the proxy) but a KNOWN-stale date is rejected.
        $published = $c['published_at'] ?? null;
        if ($published instanceof Carbon) {
            $now = $c['now'] ?? Carbon::now();
            $recencyDays = max(1, (int) ($c['recency_days'] ?? 30));
            // Allow a small future skew (timezone/clock drift) but reject the
            // genuinely stale.
            if ($published->lt($now->copy()->subDays($recencyDays))) {
                return false;
            }
        }

        return true;
    }

    /** True only for a syntactically valid http/https URL with a host. */
    public static function isHttpUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true)
            && is_string($host) && $host !== '';
    }

    /**
     * Canonical URL for dedup: lowercase scheme+host, strip trailing slash and
     * fragment. Keeps query (different query = different article on many CMSes).
     */
    public static function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return mb_strtolower(trim($url));
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$path}{$query}";
    }

    private static function parseTime(mixed $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }
        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
