<?php

namespace App\Console\Commands;

use App\Jobs\IngestPublishedPostToCorpus;
use App\Models\BrandCorpusItem;
use App\Models\ScheduledPost;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill: embeds already-PUBLISHED posts into brand_corpus as
 * `historical_post` rows, so the publish→corpus loop covers history that
 * shipped BEFORE IngestPublishedPostToCorpus existed.
 *
 * Without this, the dedup gate (ComplianceAgent::checkDedup) and the Writer's
 * RAG only ever see posts published after the loop went live — months of an
 * existing brand's real output stay invisible, and the Strategist keeps
 * recycling against an empty memory until enough new posts accrue. Run this
 * once per live brand after deploy (Bear Hug, HQ) to seed the corpus from the
 * back catalogue.
 *
 * Idempotency: mirrors the job exactly — skips any published post already
 * represented (same brand + source_url, or same body when no URL) and skips
 * stub bodies. Safe to re-run; --dry-run reports counts and writes nothing.
 *
 * Cost/latency: embeds in batches via EmbeddingService::embedMany() and logs
 * real accepted/skipped totals (Truthfulness Contract — no fabricated numbers).
 */
class CorpusBackfillPublished extends Command
{
    protected $signature = 'corpus:backfill-published
                            {--brand= : limit to a single Brand id}
                            {--days=180 : only published posts within this many days back}
                            {--batch=64 : embed/insert batch size}
                            {--dry-run : report counts only, write nothing}';

    protected $description = 'Embed already-published posts into brand_corpus (historical_post) so dedup + Writer RAG see the back catalogue.';

    public function handle(EmbeddingService $embeddings): int
    {
        $dry = (bool) $this->option('dry-run');
        $brandId = $this->option('brand') ? (int) $this->option('brand') : null;
        $days = max(1, (int) $this->option('days'));
        $batchSize = max(1, (int) $this->option('batch'));
        $since = now()->subDays($days);

        $accepted = 0;
        $skippedExisting = 0;
        $skippedStub = 0;
        $skippedNoBrand = 0;
        $embedFailures = 0;

        // NB: no ->orderBy() here. chunkById() paginates with `WHERE id > ?
        // ORDER BY id` — adding any other ordering (e.g. orderByDesc(
        // published_at)) corrupts that cursor, so rows get re-scanned and the
        // counts over-report (observed 270 posts → 305 "would ingest" in a prod
        // dry-run). A backfill processes the whole set, so ordering is cosmetic;
        // we let chunkById own the id ordering and stay exactly-once.
        $query = ScheduledPost::query()
            ->with(['draft', 'brand.workspace'])
            ->where('status', 'published')
            ->where('published_at', '>=', $since)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId));

        $total = (clone $query)->count();
        $this->info("Scanning {$total} published post(s) since {$since->toDateString()}".($brandId ? " for brand #{$brandId}" : '').'.');

        $query->chunkById($batchSize, function ($posts) use (
            $embeddings, $dry, &$accepted, &$skippedExisting, &$skippedStub, &$skippedNoBrand, &$embedFailures
        ): void {
            // Build the to-embed list for this chunk, applying the same guards
            // and idempotency check the job uses, then batch-embed the survivors.
            $pending = [];   // [ ['post'=>..., 'body'=>..., 'url'=>...], ... ]
            $texts = [];

            foreach ($posts as $post) {
                $brand = $post->brand;
                $body = trim((string) ($post->draft?->body ?? ''));

                if (! $brand) {
                    $skippedNoBrand++;
                    continue;
                }
                if (! IngestPublishedPostToCorpus::bodyIsIndexable($body)) {
                    $skippedStub++;
                    continue;
                }

                $url = $post->platform_post_url ?: null;
                $exists = BrandCorpusItem::query()
                    ->where('brand_id', $brand->id)
                    ->where('source_type', 'historical_post')
                    ->when(
                        $url !== null,
                        fn ($q) => $q->where('source_url', $url),
                        fn ($q) => $q->where('content', $body),
                    )
                    ->exists();
                if ($exists) {
                    $skippedExisting++;
                    continue;
                }

                $pending[] = ['post' => $post, 'body' => $body, 'url' => $url];
                $texts[] = $body;
            }

            if ($pending === []) {
                return;
            }

            if ($dry) {
                $accepted += count($pending);
                return;
            }

            // One embedding call per (brand, workspace) group so cost is logged
            // to the right tenant ledger. Posts in a chunk usually share a brand
            // when --brand is set; group defensively for the all-brands run.
            $byBrand = [];
            foreach ($pending as $i => $row) {
                $byBrand[$row['post']->brand->id][] = ['row' => $row, 'text' => $texts[$i]];
            }

            foreach ($byBrand as $items) {
                $brand = $items[0]['row']['post']->brand;
                try {
                    $vectors = $embeddings->embedMany(
                        array_map(fn ($it) => $it['text'], $items),
                        $brand,
                        $brand->workspace,
                    );
                } catch (\Throwable $e) {
                    $embedFailures += count($items);
                    Log::warning('CorpusBackfillPublished: embedding batch failed', [
                        'brand_id' => $brand->id,
                        'count' => count($items),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                foreach ($items as $j => $it) {
                    $post = $it['row']['post'];
                    $platform = (string) ($post->draft?->platform ?? 'social');
                    $when = $post->published_at?->format('M j, Y') ?? 'recent';

                    BrandCorpusItem::create([
                        'brand_id' => $brand->id,
                        'source_type' => 'historical_post',
                        'source_label' => "Published {$platform} · {$when}",
                        'source_url' => $it['row']['url'],
                        'source_published_at' => $post->published_at,
                        'content' => $it['row']['body'],
                        'embedding' => $vectors[$j],
                    ]);
                    $accepted++;
                }
            }
        });

        $verb = $dry ? 'Would ingest' : 'Ingested';
        $this->info("{$verb}: {$accepted}. Skipped — already indexed: {$skippedExisting}, stub body: {$skippedStub}, no brand: {$skippedNoBrand}, embed failures: {$embedFailures}.");

        Log::info('CorpusBackfillPublished complete', [
            'dry_run' => $dry,
            'brand_id' => $brandId,
            'days' => $days,
            'accepted' => $accepted,
            'skipped_existing' => $skippedExisting,
            'skipped_stub' => $skippedStub,
            'skipped_no_brand' => $skippedNoBrand,
            'embed_failures' => $embedFailures,
        ]);

        return self::SUCCESS;
    }
}
