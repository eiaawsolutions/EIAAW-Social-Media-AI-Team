<?php

namespace App\Services\Intel;

use Carbon\Carbon;

/**
 * Normalises raw provider rows (Meta Ad Library, LinkedIn DSA via Firecrawl)
 * into the unified competitor_ads schema. Pure functions — no I/O.
 *
 * dedup_hash: sha1 over a canonical key so repeated weekly runs upsert
 * the same ad instead of inserting duplicates. Whatever fields we have
 * for the hash matters less than that the same provider + same competitor
 * + same body + same asset URL always produce the same hash.
 */
final class CompetitorAdNormalizer
{
    /**
     * @param array<string,mixed> $row Raw Meta ad_library data row
     * @return array<string,mixed>     Shape ready for CompetitorAd::create
     */
    public static function fromMeta(
        array $row,
        int $brandId,
        int $workspaceId,
        string $competitorHandle,
        ?string $competitorLabel,
        int $retentionDays,
        ?int $pipelineRunId = null,
    ): array {
        $bodies = (array) ($row['ad_creative_bodies'] ?? []);
        $body = trim((string) ($bodies[0] ?? ''));

        $titles = (array) ($row['ad_creative_link_titles'] ?? []);
        $captions = (array) ($row['ad_creative_link_captions'] ?? []);
        $descriptions = (array) ($row['ad_creative_link_descriptions'] ?? []);

        // Compose body if Meta only returned secondary fields (titles/descriptions).
        if ($body === '' && ($titles || $descriptions)) {
            $body = trim(implode(' — ', array_filter([
                (string) ($titles[0] ?? ''),
                (string) ($descriptions[0] ?? ''),
            ])));
        }

        $sourceAdId = (string) ($row['id'] ?? '');
        $sourceUrl = (string) ($row['ad_snapshot_url'] ?? '');
        $cta = (string) ($captions[0] ?? '');

        $platforms = (array) ($row['publisher_platforms'] ?? []);
        $platforms = array_values(array_filter(array_map(
            fn ($p) => is_string($p) ? strtolower($p) : null,
            $platforms,
        )));

        $first = self::parseTime($row['ad_creation_time'] ?? $row['ad_delivery_start_time'] ?? null);
        $last = self::parseTime($row['ad_delivery_stop_time'] ?? null) ?? now();

        $observedAt = now();
        $expiresAt = $observedAt->copy()->addDays(max(1, $retentionDays));

        $dedupSrc = implode('|', [
            'meta',
            $competitorHandle,
            mb_substr($body, 0, 500),
            $sourceAdId ?: $sourceUrl,
        ]);

        return [
            'brand_id' => $brandId,
            'workspace_id' => $workspaceId,
            'platform' => 'meta',
            'competitor_handle' => $competitorHandle,
            'competitor_label' => $competitorLabel,
            'source_ad_id' => $sourceAdId ?: null,
            'source_url' => $sourceUrl ?: null,
            'dedup_hash' => sha1($dedupSrc),
            'body' => $body !== '' ? $body : null,
            'asset_urls' => null, // Meta API doesn't directly return asset URLs without snapshot
            'cta' => $cta !== '' ? $cta : null,
            'landing_url' => null,
            'targeting' => array_filter([
                'countries' => $row['target_locations'] ?? null,
                'languages' => $row['languages'] ?? null,
            ]),
            'platforms_seen_on' => $platforms ?: null,
            'first_seen_at' => $first,
            'last_seen_at' => $last,
            'observed_at' => $observedAt,
            'expires_at' => $expiresAt,
            'pipeline_run_id' => $pipelineRunId,
        ];
    }

    /**
     * @param array<string,mixed> $row Firecrawl-extracted LinkedIn ad row
     * @return array<string,mixed>
     */
    public static function fromLinkedin(
        array $row,
        int $brandId,
        int $workspaceId,
        string $competitorHandle,
        ?string $competitorLabel,
        int $retentionDays,
        ?int $pipelineRunId = null,
    ): array {
        $body = trim((string) ($row['body'] ?? ''));
        $imageUrl = trim((string) ($row['image_url'] ?? ''));
        $adUrl = trim((string) ($row['ad_url'] ?? ''));
        $sourceAdId = trim((string) ($row['ad_id'] ?? ''));
        $cta = trim((string) ($row['cta_text'] ?? ''));
        $landingUrl = trim((string) ($row['landing_url'] ?? ''));

        $first = self::parseTime($row['first_seen_date'] ?? null);

        $observedAt = now();
        $expiresAt = $observedAt->copy()->addDays(max(1, $retentionDays));

        $dedupSrc = implode('|', [
            'linkedin',
            $competitorHandle,
            mb_substr($body, 0, 500),
            $sourceAdId ?: $adUrl,
            $imageUrl,
        ]);

        return [
            'brand_id' => $brandId,
            'workspace_id' => $workspaceId,
            'platform' => 'linkedin',
            'competitor_handle' => $competitorHandle,
            'competitor_label' => $competitorLabel,
            'source_ad_id' => $sourceAdId !== '' ? $sourceAdId : null,
            'source_url' => $adUrl !== '' ? $adUrl : null,
            'dedup_hash' => sha1($dedupSrc),
            'body' => $body !== '' ? $body : null,
            'asset_urls' => $imageUrl !== '' ? [$imageUrl] : null,
            'cta' => $cta !== '' ? $cta : null,
            'landing_url' => $landingUrl !== '' ? $landingUrl : null,
            'targeting' => null,
            'platforms_seen_on' => ['linkedin'],
            'first_seen_at' => $first,
            'last_seen_at' => $observedAt,
            'observed_at' => $observedAt,
            'expires_at' => $expiresAt,
            'pipeline_run_id' => $pipelineRunId,
        ];
    }

    private static function parseTime(mixed $raw): ?Carbon
    {
        if (! $raw) return null;
        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
