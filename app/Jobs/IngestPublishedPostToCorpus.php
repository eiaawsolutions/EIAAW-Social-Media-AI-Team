<?php

namespace App\Jobs;

use App\Models\BrandCorpusItem;
use App\Models\ScheduledPost;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Embeds a freshly-PUBLISHED post into brand_corpus as a `historical_post`,
 * closing the publish→corpus loop that was previously open.
 *
 * Why this exists: brand_corpus.source_type='historical_post' rows were ONLY
 * ever created by two manual paths — the operator's BrandCorpusSeed page and
 * the DebugCorpus dev command. Nothing fed the AI's OWN published posts back
 * in. So the Compliance dedup gate (ComplianceAgent::checkDedup) queried an
 * empty set for any brand that never manually seeded a corpus, hit its
 * "No prior posts indexed — not applicable" branch, and passed every draft as
 * original however similar it was to last month's. The Writer's RAG retrieval
 * was equally starved. This job makes every published post part of the brand's
 * own history, so BOTH the dedup gate and the Writer's voice grounding finally
 * have real data.
 *
 * Dispatched (not run inline) from SubmitScheduledPost::applyPublished() so an
 * embedding outage can NEVER roll back a verified publish — a post being live
 * on the network is the source of truth; corpus ingestion is best-effort
 * downstream telemetry.
 *
 * Idempotency: skips if a historical_post row already exists for this brand
 * with the same source_url (re-publish / retry safety), and skips stub bodies
 * (< MIN_BODY_CHARS) so the dedup corpus isn't polluted with empty captions.
 */
class IngestPublishedPostToCorpus implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    /** Below this, a body is too thin to be a meaningful dedup/voice signal. */
    public const MIN_BODY_CHARS = 40;

    /**
     * Whether a post body is substantial enough to index into the dedup/voice
     * corpus. Pure + static so the guard is unit-testable without a DB (the
     * suite is DB-free — local .env points at prod Postgres). The backfill
     * command applies the same predicate, so they can't drift.
     */
    public static function bodyIsIndexable(?string $body): bool
    {
        return mb_strlen(trim((string) $body)) >= self::MIN_BODY_CHARS;
    }

    public function __construct(public int $scheduledPostId) {}

    public function handle(EmbeddingService $embeddings): void
    {
        // Eager-load the chain we read: draft (body/platform) + brand (+workspace
        // for the embedding cost ledger). Avoids lazy-load N+1 in the worker.
        $post = ScheduledPost::with(['draft.calendarEntry', 'brand.workspace'])->find($this->scheduledPostId);
        if (! $post) {
            return; // row vanished — nothing to ingest
        }

        if ($post->status !== 'published') {
            return; // only published posts become history; never index a draft/failed row
        }

        $brand = $post->brand;
        $body = trim((string) ($post->draft?->body ?? ''));
        if (! $brand || ! self::bodyIsIndexable($body)) {
            return; // no brand, or a stub body not worth indexing
        }

        $sourceUrl = $post->platform_post_url ?: null;

        // Idempotency: one corpus row per published URL. Re-publishes and queue
        // retries must not duplicate. When the platform gave us no URL we fall
        // back to a body match so we still don't double-index the same caption.
        $exists = BrandCorpusItem::query()
            ->where('brand_id', $brand->id)
            ->where('source_type', 'historical_post')
            ->when(
                $sourceUrl !== null,
                fn ($q) => $q->where('source_url', $sourceUrl),
                fn ($q) => $q->where('content', $body),
            )
            ->exists();
        if ($exists) {
            return;
        }

        try {
            $vector = $embeddings->embed($body, $brand, $brand->workspace);
        } catch (\Throwable $e) {
            // Embedding outage (e.g. Voyage 5xx / key flap). The post is already
            // live — never fail the worker over telemetry. A later publish or
            // the corpus:backfill-published command can re-ingest.
            Log::warning('IngestPublishedPostToCorpus: embedding failed', [
                'scheduled_post_id' => $post->id,
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $platform = (string) ($post->draft?->platform ?? 'social');
        $when = $post->published_at?->format('M j, Y') ?? 'recent';

        BrandCorpusItem::create([
            'brand_id' => $brand->id,
            'source_type' => 'historical_post', // reuse existing enum: dedup query + Writer RAG benefit with zero migration
            'source_label' => "Published {$platform} · {$when}",
            'source_url' => $sourceUrl,
            'source_published_at' => $post->published_at,
            'content' => $body,
            'embedding' => $vector,
        ]);
    }
}
