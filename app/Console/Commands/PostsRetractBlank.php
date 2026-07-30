<?php

namespace App\Console\Commands;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Models\BrandCorpusItem;
use App\Models\ScheduledPost;
use App\Services\Imagery\RenderedMediaInspector;
use Illuminate\Console\Command;

/**
 * Retracts posts that published with a blank image, and redrafts them with real
 * creative. Built for the 2026-07-29/30 incident (scheduled_posts #541-#546) but
 * written to be reusable for any future blank that slips a gate.
 *
 * WHAT THIS CAN AND CANNOT DO
 * ---------------------------
 * It CANNOT delete the live post from the social network. Metricool is a
 * scheduler, not a post-management proxy: per its own documentation, deleting a
 * post in Metricool "won't be deleted from the social network even if it was
 * already published". We hold no first-party OAuth tokens of our own (Metricool
 * owns them), and Instagram's Content Publishing API has no delete endpoint for
 * published media at all. Removing the live post is a MANUAL step in each
 * platform's app — this command prints the exact URLs for it.
 *
 * What it DOES do, in order, per post:
 *   1. Re-verifies the image really is blank. A post whose creative is fine is
 *      skipped — retraction is destructive to a published record and must never
 *      fire on a false positive.
 *   2. Marks the scheduled_post `cancelled` and records why. It is no longer a
 *      live receipt, so the Live Feed should stop counting it.
 *   3. Deletes the brand_corpus `historical_post` row keyed on the post URL.
 *      This is a truthfulness fix, not a convenience: once retracted the post is
 *      no longer published history, so it must not sit in the corpus claiming to
 *      be. It also unblocks the dedup check, which would otherwise fail the
 *      redraft at ~1.0 similarity against the very post being replaced.
 *   4. Retires the blank asset_url into asset_urls history and clears it, so
 *      DesignerAgent does not no-op on the existing asset.
 *   5. Re-runs DesignerAgent to generate and COMPOSE a real poster.
 *   6. Re-inspects the new image and fails loudly if it is blank again. The
 *      whole point is not to trade one blank for another.
 *   7. Re-runs the real ComplianceAgent and lets IT own the status transition.
 *      Status is never hand-forced to approved.
 *
 * Scheduling is deliberately NOT part of this command. Republishing is a
 * separate outward-facing decision — use `scheduler` / the panel once the
 * redrafted creative has been eyeballed.
 *
 * Dry-run by default.
 */
class PostsRetractBlank extends Command
{
    protected $signature = 'posts:retract-blank
                            {posts : CSV of scheduled_post ids to retract and redraft}
                            {--apply : Actually make the changes (default: dry run)}
                            {--force : Retract even if the image does not look blank (requires --apply)}';

    protected $description = 'Retract posts published with a blank image and redraft them with real creative.';

    public function handle(): int
    {
        $ids = array_values(array_filter(array_map(
            static fn ($v) => (int) trim($v),
            explode(',', (string) $this->argument('posts')),
        )));

        if ($ids === []) {
            $this->error('No scheduled_post ids given.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        if (! $apply) {
            $this->warn('DRY RUN — no changes will be made. Re-run with --apply to commit.');
        }

        $posts = ScheduledPost::with(['draft.brand', 'brand'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($posts->isEmpty()) {
            $this->error('None of those scheduled_post ids exist.');

            return self::FAILURE;
        }

        $inspector = app(RenderedMediaInspector::class);
        $manualDeletions = [];
        $redrafted = 0;
        $skipped = 0;

        foreach ($posts as $post) {
            $draft = $post->draft;
            $brand = $draft?->brand ?? $post->brand;

            $this->newLine();
            $this->line("<fg=cyan>── post #{$post->id} / draft #{$draft?->id} · {$draft?->platform} ──</>");

            if (! $draft || ! $brand) {
                $this->warn('  Skipped: draft or brand missing.');
                $skipped++;

                continue;
            }

            // ── 1. Prove it is actually blank ──
            $before = $inspector->inspectUrl((string) $draft->asset_url);
            $this->line('  current image: '.$before['verdict'].' — '.mb_substr($before['reason'], 0, 80));

            if ($before['ok'] && ! $force) {
                $this->warn('  Skipped: this image is NOT blank. Use --force only if you are certain.');
                $skipped++;

                continue;
            }

            $manualDeletions[] = [
                $post->id,
                (string) $draft->platform,
                (string) $post->platform_post_url,
            ];

            if (! $apply) {
                $this->line('  would: cancel post, drop corpus row, clear asset, re-run Designer + Compliance');
                $redrafted++;

                continue;
            }

            // ── 2. Retract the receipt ──
            $post->update([
                'status' => 'cancelled',
                'last_error' => 'Retracted '.now()->toDateString().': published with a blank image (see media_quality gate). '
                    .'Live post must be deleted manually on the platform — no API supports it.',
            ]);
            $this->line('  <fg=yellow>retracted</> scheduled_post → cancelled');

            // ── 3. Un-publish it from the corpus ──
            $corpusDeleted = 0;
            if ($post->platform_post_url) {
                $corpusDeleted = BrandCorpusItem::where('brand_id', $brand->id)
                    ->where('source_type', 'historical_post')
                    ->where('source_url', $post->platform_post_url)
                    ->delete();
            }
            if ($corpusDeleted === 0) {
                // No URL match — fall back to the body match the ingester uses.
                $corpusDeleted = BrandCorpusItem::where('brand_id', $brand->id)
                    ->where('source_type', 'historical_post')
                    ->where('content', (string) $draft->body)
                    ->delete();
            }
            $this->line("  corpus rows removed: {$corpusDeleted}");

            // ── 4. Retire the blank asset ──
            $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
            if ($draft->asset_url && ! in_array($draft->asset_url, $history, true)) {
                $history[] = $draft->asset_url;
            }
            $draft->update(['asset_url' => null, 'asset_urls' => array_values($history)]);

            // ── 5. Regenerate ──
            $this->line('  regenerating image…');
            $design = app(DesignerAgent::class)->run($brand, ['draft_id' => $draft->id]);
            if (! $design->ok) {
                $this->error('  Designer failed: '.mb_substr((string) $design->errorMessage, 0, 160));
                $skipped++;

                continue;
            }

            $draft->refresh();

            // ── 6. Prove the replacement is real ──
            $after = $inspector->inspectUrl((string) $draft->asset_url);
            $contract = is_array($draft->branding_payload['render_contract'] ?? null)
                ? $draft->branding_payload['render_contract']
                : null;

            if (! $after['ok'] || ComplianceAgent::contractProvesBlank($contract)) {
                $this->error('  NEW IMAGE IS STILL BLANK — '.mb_substr($after['reason'], 0, 120));
                $this->error('  Draft left without a clean asset. Investigate before republishing.');
                $skipped++;

                continue;
            }

            $this->info(sprintf(
                '  new image OK (edge %.4f) %s',
                (float) ($after['metrics']['edge_density'] ?? 0),
                (string) $draft->asset_url,
            ));

            // ── 7. Real compliance owns the status ──
            $compliance = app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
            $draft->refresh();
            $this->line('  compliance → <options=bold>'.$draft->status.'</>'
                .($compliance->ok ? '' : ' (agent error: '.mb_substr((string) $compliance->errorMessage, 0, 80).')'));

            $redrafted++;
        }

        // ── The part no API can do ──
        $this->newLine(2);
        $this->line('<fg=red;options=bold>MANUAL STEP — delete these live posts in each platform\'s own app.</>');
        $this->line('Metricool cannot remove already-published posts, and Instagram has no delete API.');
        $this->newLine();
        if ($manualDeletions !== []) {
            $this->table(['post', 'platform', 'url to open and delete'], $manualDeletions);
        }

        $this->newLine();
        $this->line(sprintf('Redrafted: <fg=green>%d</> | Skipped: <fg=yellow>%d</>', $redrafted, $skipped));

        if (! $apply) {
            $this->newLine();
            $this->warn('Nothing was changed. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
