<?php

namespace Tests\Unit;

use App\Console\Commands\PostsDispatchDue;
use App\Console\Commands\PostsRepointRevokedConnection;
use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use App\Jobs\SubmitScheduledPost;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\Config;
use ReflectionClass;
use Tests\TestCase;

/**
 * Fix for the 2026-06-13 "revoked Instagram connection auto-retries forever"
 * report. A scheduled post failed with last_error "Platform connection is not
 * active (status=revoked)." but the UI promised "AUTO: cron will retry in
 * ~5 min (attempt 1/3)". A revoked connection is PERMANENT — retrying burns
 * the 3-attempt budget producing the identical failure and misleads the
 * operator. The cron must now SKIP auto-retrying permanent failures while
 * still retrying transient ones, and the operator-facing copy must point at
 * the real fix (reconnect → Retry).
 *
 * Pure-decision tests run on in-memory models (DB-free). The cron retry pass
 * is DB-driven and the suite is DB-free (SMT local .env points at Railway
 * PROD), so the cron + producer↔matcher guarantees are locked source-level.
 */
class PermanentFailureNoAutoRetryTest extends TestCase
{
    private function failedPost(?string $lastError, int $attempt = 1): ScheduledPost
    {
        $p = new ScheduledPost();
        $p->status = 'failed';
        $p->attempt_count = $attempt;
        $p->last_error = $lastError;
        return $p;
    }

    // --- A. Pure decision: permanent vs transient classification ---

    public function test_each_permanent_signature_is_classified_permanent(): void
    {
        // Exactly the strings SubmitScheduledPost::markFailed() produces.
        $permanent = [
            'Platform connection is not active (status=revoked).',
            'Platform connection is not active (status=expired).',
            'Draft or platform connection missing.',
            'Publishability gate (pre-publish): instagram requires media | text-only post',
            'Video-format draft has a still image as its primary asset (asset_url is .jpg/.png/etc). '
                . 'Re-run VideoAgent on this draft.',
        ];

        foreach ($permanent as $reason) {
            $this->assertTrue(
                ScheduledPost::isPermanentFailureReason($reason),
                "Expected permanent classification for: {$reason}"
            );
        }
    }

    public function test_transient_and_null_failures_are_not_permanent(): void
    {
        $this->assertFalse(ScheduledPost::isPermanentFailureReason('Platform rejected: 422 Unprocessable'));
        $this->assertFalse(ScheduledPost::isPermanentFailureReason('Metricool submit failed: 503 upstream'));
        $this->assertFalse(ScheduledPost::isPermanentFailureReason('Stuck in submitting after 3 attempts (worker died mid-submit before the provider replied).'));
        $this->assertFalse(ScheduledPost::isPermanentFailureReason(null));
        $this->assertFalse(ScheduledPost::isPermanentFailureReason(''));
    }

    public function test_is_permanently_failed_requires_failed_status(): void
    {
        $revoked = 'Platform connection is not active (status=revoked).';

        $this->assertTrue($this->failedPost($revoked)->isPermanentlyFailed());

        // Same error but not in 'failed' status → not permanently-failed.
        $requeued = $this->failedPost($revoked);
        $requeued->status = 'queued';
        $this->assertFalse($requeued->isPermanentlyFailed());
    }

    // --- B. Pure decision: operator-facing nextActionFor copy ---

    public function test_revoked_connection_copy_says_reconnect_not_cron_will_retry(): void
    {
        Config::set('services.publishing.provider', 'metricool');

        $copy = ScheduledPostResource::nextActionFor(
            $this->failedPost('Platform connection is not active (status=revoked).', attempt: 0)
        );

        $this->assertStringContainsString('reconnect', $copy);
        $this->assertStringNotContainsString('cron will retry', $copy);
    }

    public function test_other_permanent_failure_copy_points_at_re_evaluate(): void
    {
        $copy = ScheduledPostResource::nextActionFor(
            $this->failedPost('Publishability gate (pre-publish): text-only post', attempt: 0)
        );

        $this->assertStringContainsString('Re-evaluate', $copy);
        $this->assertStringNotContainsString('cron will retry', $copy);
    }

    public function test_transient_failure_copy_still_promises_auto_retry(): void
    {
        $copy = ScheduledPostResource::nextActionFor(
            $this->failedPost('Platform rejected: 422 Unprocessable', attempt: 1)
        );

        $this->assertStringContainsString('cron will retry', $copy);
        $this->assertStringContainsString('attempt 2/3', $copy);
    }

    public function test_manual_retry_button_stays_available_for_permanent_failures(): void
    {
        // canRetry() is attempt-count-only on purpose: a manual Retry is the
        // operator's explicit override (they may have just reconnected).
        $this->assertTrue(
            $this->failedPost('Platform connection is not active (status=revoked).', attempt: 1)->canRetry()
        );
    }

    // --- C. Source-level guard: cron skips permanent failures ---

    public function test_dispatcher_excludes_permanent_signatures_from_auto_retry(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PostsDispatchDue::class))->getFileName()
        );

        // Branch 3 filters the failed-retry query against the shared set…
        $this->assertStringContainsString('PERMANENT_FAILURE_SIGNATURES', $src);
        // …by excluding those last_error strings.
        $this->assertStringContainsString("'not like'", $src);
        // Held rows are observable in the tick log.
        $this->assertStringContainsString('retries_skipped_permanent', $src);
    }

    // --- D. Producer↔matcher agreement (prevents Option-B brittleness) ---

    public function test_every_permanent_signature_still_appears_in_the_job_source(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SubmitScheduledPost::class))->getFileName()
        );

        foreach (ScheduledPost::PERMANENT_FAILURE_SIGNATURES as $signature) {
            $this->assertStringContainsString(
                $signature,
                $src,
                "markFailed() no longer emits the permanent signature '{$signature}' — "
                . 'update PERMANENT_FAILURE_SIGNATURES in the same change or the cron matcher goes stale.'
            );
        }
    }

    public function test_transient_provider_rejection_strings_are_not_in_the_permanent_set(): void
    {
        // Guard against accidentally folding a transient failure into the
        // permanent set (which would stop its legitimate 3 retries).
        foreach (ScheduledPost::PERMANENT_FAILURE_SIGNATURES as $signature) {
            $this->assertStringNotContainsString('Platform rejected', $signature);
            $this->assertStringNotContainsString('submit failed', $signature);
        }
    }

    public function test_repoint_command_signature_belongs_to_the_shared_set(): void
    {
        $ref = new ReflectionClass(PostsRepointRevokedConnection::class);
        $signature = $ref->getConstant('SIGNATURE');

        $this->assertContains(
            $signature,
            ScheduledPost::PERMANENT_FAILURE_SIGNATURES,
            'The repoint command and the cron skip must key off the same signature.'
        );
    }
}
