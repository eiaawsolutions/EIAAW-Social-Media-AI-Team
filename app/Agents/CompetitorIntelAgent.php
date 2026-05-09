<?php

namespace App\Agents;

use App\Models\Brand;
use App\Models\CompetitorAd;
use App\Services\Intel\CompetitorAdNormalizer;
use App\Services\Intel\LinkedinAdLibraryFirecrawlClient;
use App\Services\Intel\MetaAdLibraryClient;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls competitor ad creatives from Meta Ad Library + LinkedIn (via Firecrawl
 * on the EU DSA portal) on a weekly cadence. Persists to competitor_ads with
 * a 30-day rolling window. Strategist consumes this on the next calendar run.
 *
 * Why this sits between Onboarding and Strategist instead of being inside
 * StrategistAgent:
 *   - Network I/O is slow + flaky; we don't want to gate calendar synthesis
 *     on Firecrawl latency.
 *   - It's a separate cron beat (weekly Mon 03:00, before Optimizer runs at
 *     02:00 — wait, Optimizer runs first, then this. Strategist reads both).
 *   - Operator can pause intel without affecting calendar generation.
 *
 * Failure mode: if both providers fail, the agent ships zero new ads but
 * does NOT delete existing ones — the Strategist still sees last week's
 * intel. Empty intel is a soft signal, not a hard failure.
 */
class CompetitorIntelAgent extends BaseAgent
{
    /** No readiness gating — this can run before any drafts exist. */
    protected array $requiredStages = [];

    public function __construct(
        LlmGateway $llm,
        private readonly MetaAdLibraryClient $meta,
        private readonly LinkedinAdLibraryFirecrawlClient $linkedin,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'competitor_intel'; }
    public function promptVersion(): string { return 'competitor_intel.v1.0'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        if (! (bool) config('services.competitor_intel.enabled', true)) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => 'competitor_intel disabled',
            ]);
        }

        $config = $brand->competitor_intel_config ?? [];
        if (! ($config['enabled'] ?? true)) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => 'disabled per brand',
            ]);
        }

        $handles = $config['handles'] ?? [];
        if (empty($handles)) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => 'no handles configured',
            ]);
        }

        $maxHandles = (int) config('services.competitor_intel.max_handles_per_brand', 10);
        $maxAdsPerHandle = (int) config('services.competitor_intel.max_ads_per_handle', 25);
        $retentionDays = (int) config('services.competitor_intel.retention_days', 30);

        $handles = array_slice($handles, 0, $maxHandles);
        $geoCodes = (array) ($config['geo_codes'] ?? []);

        $stats = ['meta_fetched' => 0, 'meta_inserted' => 0, 'linkedin_fetched' => 0, 'linkedin_inserted' => 0, 'errors' => 0];

        // Pre-resolve Meta token once per brand (it's the same token across handles).
        $metaToken = $this->meta->tokenForBrand($brand);

        foreach ($handles as $handle) {
            $platform = strtolower((string) ($handle['platform'] ?? ''));
            $label = $handle['label'] ?? null;

            try {
                if ($platform === 'meta') {
                    $pageId = (string) ($handle['page_id'] ?? '');
                    if ($pageId === '') continue;

                    $rows = $this->meta->fetchAdsForPage($metaToken, $pageId, $geoCodes, $maxAdsPerHandle);
                    $stats['meta_fetched'] += count($rows);
                    $stats['meta_inserted'] += $this->upsertRows($rows, fn ($row) => CompetitorAdNormalizer::fromMeta(
                        $row, $brand->id, $brand->workspace_id, $pageId, $label, $retentionDays,
                    ));
                } elseif ($platform === 'linkedin') {
                    $slug = (string) ($handle['company_slug'] ?? '');
                    if ($slug === '') continue;

                    $rows = $this->linkedin->fetchAdsForCompany($slug, $maxAdsPerHandle);
                    $stats['linkedin_fetched'] += count($rows);
                    $stats['linkedin_inserted'] += $this->upsertRows($rows, fn ($row) => CompetitorAdNormalizer::fromLinkedin(
                        $row, $brand->id, $brand->workspace_id, $slug, $label, $retentionDays,
                    ));
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('CompetitorIntelAgent: handle fetch failed', [
                    'brand_id' => $brand->id,
                    'handle' => $handle,
                    'error' => substr($e->getMessage(), 0, 240),
                ]);
            }
        }

        // Prune expired rows so the table doesn't grow unbounded. Cheap query
        // because (expires_at) is indexed.
        CompetitorAd::query()
            ->where('brand_id', $brand->id)
            ->where('expires_at', '<=', now())
            ->delete();

        // Refresh last_refreshed_at on the brand so the operator's Settings
        // page can show "Last refresh: 2 days ago".
        $newConfig = array_merge($config, [
            'last_refreshed_at' => now()->toIso8601String(),
        ]);
        $brand->forceFill(['competitor_intel_config' => $newConfig])->save();

        return AgentResult::ok([
            'brand_id' => $brand->id,
            'handles_processed' => count($handles),
            'meta_fetched' => $stats['meta_fetched'],
            'meta_inserted' => $stats['meta_inserted'],
            'linkedin_fetched' => $stats['linkedin_fetched'],
            'linkedin_inserted' => $stats['linkedin_inserted'],
            'errors' => $stats['errors'],
        ]);
    }

    /**
     * Bulk-upsert into competitor_ads. Returns the number of NEW rows
     * inserted (existing dedup_hash matches refresh observed_at + expires_at
     * but don't bump the count — we only count fresh discoveries).
     *
     * @param array<int,array<string,mixed>> $rawRows
     * @param callable(array): array $normalizer fn(rawRow): normalised payload
     */
    private function upsertRows(array $rawRows, callable $normalizer): int
    {
        if (empty($rawRows)) return 0;

        $inserted = 0;
        foreach ($rawRows as $raw) {
            try {
                $payload = $normalizer($raw);
            } catch (\Throwable $e) {
                Log::warning('CompetitorIntelAgent: normalize failed', [
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
                continue;
            }

            // ON CONFLICT (brand_id, platform, dedup_hash): keep existing
            // first_seen_at, refresh observed_at + expires_at + last_seen_at.
            // Stats counter only bumps when an INSERT (not an update) happens.
            $existed = DB::table('competitor_ads')
                ->where('brand_id', $payload['brand_id'])
                ->where('platform', $payload['platform'])
                ->where('dedup_hash', $payload['dedup_hash'])
                ->exists();

            if ($existed) {
                DB::table('competitor_ads')
                    ->where('brand_id', $payload['brand_id'])
                    ->where('platform', $payload['platform'])
                    ->where('dedup_hash', $payload['dedup_hash'])
                    ->update([
                        'observed_at' => $payload['observed_at'],
                        'expires_at' => $payload['expires_at'],
                        'last_seen_at' => $payload['last_seen_at'] ?? $payload['observed_at'],
                        'updated_at' => now(),
                    ]);
                continue;
            }

            try {
                CompetitorAd::create($payload);
                $inserted++;
            } catch (\Throwable $e) {
                Log::warning('CompetitorIntelAgent: insert failed', [
                    'error' => substr($e->getMessage(), 0, 200),
                ]);
            }
        }
        return $inserted;
    }
}
