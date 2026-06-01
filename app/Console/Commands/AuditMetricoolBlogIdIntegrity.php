<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * audit:metricool-blogid-integrity — read-only verifier that publishing is
 * tenant-isolated under the Metricool model. The Metricool equivalent of
 * audit:blotato-leakage, but for the OPPOSITE multi-tenancy model.
 *
 * Background ([[metricool_multitenancy]]): unlike Blotato (one account/key per
 * workspace), Metricool is ONE shared agency account where each tenant is a
 * brand identified by brands.metricool_blog_id. At publish time
 * MetricoolPublisher::submit() routes a post to `$post->brand->metricool_blog_id`
 * + the network — so the ENTIRE isolation guarantee reduces to four invariants:
 *
 *   1. Every post's platform_connection belongs to the SAME brand as the post.
 *      (Else target_overrides — FB/LinkedIn pageId, etc. — could carry another
 *       tenant's per-network options onto this post.)
 *   2. Every post's draft belongs to the SAME brand as the post.
 *      (Else the wrong tenant's caption/media routes through this brand's blogId.)
 *   3. No two ACTIVE brands share a metricool_blog_id.
 *      (A shared blogId would mean two tenants publish to one Metricool brand =
 *       one account — cross-tenant landing.)
 *   4. Each workspace maps to exactly ONE blogId among brands that have posted.
 *      (Belt-and-braces: proves "ws#N → blogId X" is 1:1 in live data.)
 *
 * A violation here is a CODE bug (a creation path that crossed brands), not
 * cleanable residue — so this command does not mutate anything. It exits
 * FAILURE on any violation so it can gate CI / a scheduled health check; SUCCESS
 * when all four invariants hold.
 *
 * Note on the fail-safe: a post whose brand has a NULL blog_id can NEVER
 * mis-route — MetricoolPublisher::submit() hard-fails it before any API call.
 * We report the count for visibility but it is not a violation.
 *
 * Usage:
 *   php artisan audit:metricool-blogid-integrity        # verify, exit non-zero on any violation
 */
class AuditMetricoolBlogIdIntegrity extends Command
{
    protected $signature = 'audit:metricool-blogid-integrity';

    protected $description = 'Verify Metricool publishing is tenant-isolated: posts route only through their own brand\'s unique blogId.';

    public function handle(): int
    {
        $violations = 0;

        // ── 1) post.brand_id === its connection.brand_id ──────────────────
        $this->info('─── 1) ScheduledPost ↔ PlatformConnection brand match ───');
        $connMismatch = DB::table('scheduled_posts as sp')
            ->join('platform_connections as pc', 'pc.id', '=', 'sp.platform_connection_id')
            ->whereColumn('sp.brand_id', '!=', 'pc.brand_id')
            ->select('sp.id', 'sp.brand_id as post_brand', 'pc.brand_id as conn_brand', 'sp.status')
            ->get();
        if ($connMismatch->isEmpty()) {
            $this->info('  ✓ Every post\'s connection belongs to the post\'s own brand.');
        } else {
            $violations += $connMismatch->count();
            $this->error("  ✗ {$connMismatch->count()} post(s) use a connection from a DIFFERENT brand:");
            foreach ($connMismatch as $r) {
                $this->line(sprintf('    sp#%d post_brand=%s conn_brand=%s status=%s', $r->id, $r->post_brand, $r->conn_brand, $r->status));
            }
        }

        // ── 2) post.brand_id === its draft.brand_id ───────────────────────
        $this->newLine();
        $this->info('─── 2) ScheduledPost ↔ Draft brand match ───');
        $draftMismatch = DB::table('scheduled_posts as sp')
            ->join('drafts as d', 'd.id', '=', 'sp.draft_id')
            ->whereColumn('sp.brand_id', '!=', 'd.brand_id')
            ->select('sp.id', 'sp.brand_id as post_brand', 'd.brand_id as draft_brand')
            ->get();
        if ($draftMismatch->isEmpty()) {
            $this->info('  ✓ Every post\'s draft belongs to the post\'s own brand.');
        } else {
            $violations += $draftMismatch->count();
            $this->error("  ✗ {$draftMismatch->count()} post(s) reference a draft from a DIFFERENT brand:");
            foreach ($draftMismatch as $r) {
                $this->line(sprintf('    sp#%d post_brand=%s draft_brand=%s', $r->id, $r->post_brand, $r->draft_brand));
            }
        }

        // ── 3) no two active brands share a blogId ────────────────────────
        $this->newLine();
        $this->info('─── 3) Unique blogId per active brand ───');
        $dupBlogs = DB::table('brands')
            ->whereNotNull('metricool_blog_id')
            ->whereNull('archived_at')
            ->select('metricool_blog_id', DB::raw('count(*) as cnt'))
            ->groupBy('metricool_blog_id')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($dupBlogs->isEmpty()) {
            $this->info('  ✓ Each active brand has a unique routing space (blogId).');
        } else {
            $violations += $dupBlogs->count();
            $this->error('  ✗ blogId shared by multiple active brands (two tenants → one account):');
            foreach ($dupBlogs as $d) {
                $brandList = DB::table('brands')
                    ->where('metricool_blog_id', $d->metricool_blog_id)
                    ->whereNull('archived_at')
                    ->pluck('name')->implode(', ');
                $this->line(sprintf('    blogId=%s used by %d brands: %s', $d->metricool_blog_id, $d->cnt, $brandList));
            }
        }

        // ── 4) one blogId per workspace among brands that have posted ─────
        $this->newLine();
        $this->info('─── 4) Workspace → blogId is 1:1 (brands that have posted) ───');
        $wsBlogs = DB::table('scheduled_posts as sp')
            ->join('brands as b', 'b.id', '=', 'sp.brand_id')
            ->whereNotNull('b.metricool_blog_id')
            ->select('b.workspace_id', 'b.metricool_blog_id')
            ->distinct()
            ->get()
            ->groupBy('workspace_id');
        $wsViolation = false;
        foreach ($wsBlogs as $wsId => $pairs) {
            $blogIds = $pairs->pluck('metricool_blog_id')->unique();
            if ($blogIds->count() > 1) {
                $wsViolation = true;
                $violations++;
                $this->error(sprintf('  ✗ ws#%s has posted through MULTIPLE blogIds: %s', $wsId, $blogIds->implode(', ')));
            } else {
                $this->line(sprintf('  ws#%s → blogId=%s', $wsId, $blogIds->first()));
            }
        }
        if (! $wsViolation) {
            $this->info('  ✓ Every workspace that has posted routes through a single blogId.');
        }

        // ── Fail-safe visibility (not a violation) ────────────────────────
        $this->newLine();
        $nullBlogInflight = DB::table('scheduled_posts as sp')
            ->join('brands as b', 'b.id', '=', 'sp.brand_id')
            ->whereNull('b.metricool_blog_id')
            ->whereIn('sp.status', ['queued', 'submitting', 'submitted'])
            ->count();
        $this->line("In-flight posts on a brand with no blogId: {$nullBlogInflight} "
            . '(these hard-fail at publish; they never post to a wrong account).');

        // ── Verdict ───────────────────────────────────────────────────────
        $this->newLine();
        $this->info('──────── Summary ────────');
        if ($violations === 0) {
            $this->info('✓ PASS — publishing is tenant-isolated. Each brand\'s posts route only through its own unique blogId.');
            return self::SUCCESS;
        }

        $this->error("✗ FAIL — {$violations} isolation violation(s). Posts could land on the wrong tenant's account. "
            . 'This indicates a creation path that crossed brands — investigate SchedulerAgent / auto-schedule / Draft actions.');
        return self::FAILURE;
    }
}
