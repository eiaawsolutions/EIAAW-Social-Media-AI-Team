<?php

namespace App\Services\Imagery;

use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Draft;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Picks the best brand-uploaded asset for a draft via pgvector cosine match
 * against the draft's topic + visual_direction + body excerpt. Returns null
 * if no asset clears the configured similarity floor — caller falls back to
 * AI generation (or text-only) at that point.
 *
 * Tie-breakers when multiple candidates pass the floor:
 *   1. brand_approved = true wins
 *   2. Smaller use_count wins (variety; don't repeat the same hero shot)
 *   3. Older last_used_at wins (rest assets between uses)
 */
class BrandAssetPicker
{
    /** Fallback cosine distance ceiling when config is unset. The live ceiling
     *  is services.fal.library_match_distance (default 0.32) — tightened from
     *  the old 0.45 so a generic stock asset no longer wins a weak semantic
     *  match over a scripted-brief AI generation. */
    private const DEFAULT_MAX_COSINE_DISTANCE = 0.32;

    public function __construct(private readonly EmbeddingService $embeddings) {}

    private function maxDistance(): float
    {
        return (float) config('services.fal.library_match_distance', self::DEFAULT_MAX_COSINE_DISTANCE);
    }

    /**
     * @return array{asset: BrandAsset, distance: float}|null
     */
    public function pickFor(Brand $brand, Draft $draft, string $mediaType): ?array
    {
        $queryText = $this->queryTextFor($draft);
        if ($queryText === '') return null;

        try {
            $vector = $this->embeddings->embedQuery($queryText, $brand, $brand->workspace);
        } catch (\Throwable $e) {
            Log::warning('BrandAssetPicker: embed query failed', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Pull top 5 by cosine + their distances. Apply tie-breakers in PHP.
        // usage_intent = 'general' only: customised assets are reserved for the
        // one dedicated post the operator scheduled around them, so the agents
        // must never re-pick a hand-scheduled visual for an auto-planned draft.
        $rows = DB::select(
            'SELECT id, embedding <=> ? AS distance
             FROM brand_assets
             WHERE brand_id = ?
               AND media_type = ?
               AND usage_intent = ?
               AND archived_at IS NULL
               AND embedding IS NOT NULL
             ORDER BY embedding <=> ?
             LIMIT 5',
            [(string) $vector, $brand->id, $mediaType, BrandAsset::INTENT_GENERAL, (string) $vector],
        );

        if (empty($rows)) {
            // No embedded candidates at all (library empty / all un-tagged).
            // Previously we force-picked the latest brand_approved asset here
            // at distance=1.0 — which BYPASSED the distance floor and let a
            // generic off-topic stock photo land on the post. That is the
            // exact "image unrelated to the script" bug. Return null instead
            // so the caller falls through to a scripted-brief AI generation.
            return null;
        }

        // Filter by distance ceiling.
        $max = $this->maxDistance();
        $usable = array_filter($rows, fn ($r) => (float) $r->distance <= $max);
        if (empty($usable)) {
            return null;
        }

        // Hydrate then tie-break.
        $ids = array_column($usable, 'id');
        $assets = BrandAsset::whereIn('id', $ids)->get()->keyBy('id');
        $best = collect($usable)
            ->map(fn ($r) => ['asset' => $assets[$r->id], 'distance' => (float) $r->distance])
            ->sortBy([
                ['distance', 'asc'],
                fn ($a, $b) => $b['asset']->brand_approved <=> $a['asset']->brand_approved,
                fn ($a, $b) => $a['asset']->use_count <=> $b['asset']->use_count,
                fn ($a, $b) => optional($a['asset']->last_used_at)?->timestamp
                    <=> optional($b['asset']->last_used_at)?->timestamp,
            ])
            ->first();

        return $best ?: null;
    }

    public function hasAnyOfType(Brand $brand, string $mediaType): bool
    {
        return BrandAsset::where('brand_id', $brand->id)
            ->where('media_type', $mediaType)
            ->generalPool()
            ->whereNull('archived_at')
            ->exists();
    }

    private function queryTextFor(Draft $draft): string
    {
        $entry = $draft->calendarEntry;
        $topic = trim((string) ($entry?->topic ?? ''));
        $angle = trim((string) ($entry?->angle ?? ''));
        $direction = trim((string) ($entry?->visual_direction ?? ''));
        $bodyLead = (string) \Illuminate\Support\Str::words(strip_tags((string) $draft->body), 30, ' …');

        return collect([$topic, $angle, $direction, $bodyLead])
            ->filter(fn ($s) => $s !== '')
            ->implode("\n");
    }
}
