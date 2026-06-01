<?php

namespace Tests\Unit;

use App\Console\Commands\PostsDispatchDue;
use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Tests\TestCase;

/**
 * Two fixes from the 2026-06-01 "drafts stuck waiting for Blotato" report:
 *
 *  1. STALE COPY: the Schedule page's "Next step" column hardcoded "WAIT for
 *     Blotato to accept" even though publishing moved to Metricool
 *     (PUBLISH_PROVIDER=metricool). nextActionFor() must now name the LIVE
 *     publisher from config.
 *
 *  2. ORPHAN RECOVERY: posts that entered status='submitting' and whose worker
 *     died before advancing them were stranded forever — PostsDispatchDue had no
 *     branch for 'submitting'. It must now re-queue stale 'submitting' rows
 *     (>10min, no provider id, attempt<3) and fail the exhausted ones.
 *
 * The nextActionFor assertions run on an in-memory model (DB-free). The
 * orphan-recovery assertions are source-level (the SMT local .env points at
 * Railway PROD, so tests never touch the DB).
 */
class PublishStatusCopyAndOrphanRecoveryTest extends TestCase
{
    private function postWithStatus(string $status, int $attempt = 1): ScheduledPost
    {
        $p = new ScheduledPost();
        $p->status = $status;
        $p->attempt_count = $attempt;
        return $p;
    }

    public function test_next_step_names_metricool_not_blotato_when_provider_is_metricool(): void
    {
        Config::set('services.publishing.provider', 'metricool');

        $submitting = ScheduledPostResource::nextActionFor($this->postWithStatus('submitting'));
        $submitted = ScheduledPostResource::nextActionFor($this->postWithStatus('submitted'));

        $this->assertStringContainsString('Metricool', $submitting);
        $this->assertStringNotContainsString('Blotato', $submitting);
        $this->assertStringContainsString('Metricool', $submitted);
        $this->assertStringNotContainsString('Blotato', $submitted);
    }

    public function test_next_step_still_names_blotato_on_the_rollback_provider(): void
    {
        // The rollback path (PUBLISH_PROVIDER=blotato) should honestly say Blotato.
        Config::set('services.publishing.provider', 'blotato');

        $submitting = ScheduledPostResource::nextActionFor($this->postWithStatus('submitting'));
        $this->assertStringContainsString('Blotato', $submitting);
    }

    public function test_next_step_uses_neutral_phrase_for_unknown_provider(): void
    {
        Config::set('services.publishing.provider', 'something-new');

        $submitting = ScheduledPostResource::nextActionFor($this->postWithStatus('submitting'));
        $this->assertStringContainsString('the publisher', $submitting);
        $this->assertStringNotContainsString('Blotato', $submitting);
    }

    /**
     * The dispatcher must recover orphaned 'submitting' rows. Source-level lock
     * because the dispatch loop is DB-driven and the suite is DB-free.
     */
    public function test_dispatcher_has_orphan_submitting_recovery_branch(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PostsDispatchDue::class))->getFileName()
        );

        // Selects stale 'submitting' rows…
        $this->assertStringContainsString("where('status', 'submitting')", $src);
        // …only those WITHOUT a provider id (no double-post risk)…
        $this->assertStringContainsString("whereNull('blotato_post_id')", $src);
        // …and only after a window comfortably beyond the job's 120s timeout.
        $this->assertStringContainsString('subMinutes(10)', $src);
        // Re-queues the recoverable ones…
        $this->assertStringContainsString("'status' => 'queued'", $src);
        // …and fails the attempt-exhausted ones so they surface as actionable.
        $this->assertStringContainsString("'status' => 'failed'", $src);
        // Telemetry distinguishes the two outcomes.
        $this->assertStringContainsString('orphans_requeued', $src);
        $this->assertStringContainsString('orphans_failed', $src);
    }

    /**
     * Guard the safety invariants of the recovery branch so a future edit can't
     * make it re-queue a row that might already have posted, or interrupt a
     * genuinely in-flight job.
     */
    public function test_orphan_recovery_is_bounded_and_safe(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PostsDispatchDue::class))->getFileName()
        );

        // The recovery branch must bound by attempt_count (no infinite re-queue).
        $this->assertStringContainsString('attempt_count < 3', $src);
        // Must not reset rows that carry a provider id (they may have submitted).
        // i.e. the submitting-recovery query is guarded by whereNull on the id.
        $this->assertMatchesRegularExpression(
            "/where\('status', 'submitting'\)\s*->\s*whereNull\('blotato_post_id'\)/s",
            $src,
            'Orphan recovery must only touch submitting rows with NO provider id.'
        );
    }
}
