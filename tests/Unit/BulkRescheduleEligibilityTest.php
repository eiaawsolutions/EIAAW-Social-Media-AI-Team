<?php

namespace Tests\Unit;

use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use App\Models\Draft;
use App\Models\ScheduledPost;
use ReflectionClass;
use Tests\TestCase;

/**
 * Bulk "Re-schedule selected" on /agency/schedule.
 *
 * Context (2026-06-01): the per-row Reschedule button only showed on `queued`
 * rows, so the real backlog — 117 past failed/cancelled posts on the EIAAW
 * brand — could not be pushed to a new date at all, let alone in bulk. The
 * operator's ask was "reschedule all already passed drafts time and date".
 *
 * The risk: 26 of the cancelled rows had an underlying draft that had ALREADY
 * published; reviving those would double-post on live accounts. So the bulk
 * action's gate is the safety-critical part, and rescheduleEligibility() is a
 * pure function we can exercise on in-memory models (the SMT suite is DB-free —
 * local .env points at Railway PROD, see [[metrics_capture_gap]]).
 */
class BulkRescheduleEligibilityTest extends TestCase
{
    private function makePost(string $status, ?string $draftStatus, int $attempt = 3): ScheduledPost
    {
        $p = new ScheduledPost();
        $p->status = $status;
        $p->attempt_count = $attempt;
        $p->blotato_post_id = 'stale-id';
        $p->last_error = 'something broke';

        if ($draftStatus !== null) {
            $d = new Draft();
            $d->status = $draftStatus;
            $p->setRelation('draft', $d);
        } else {
            $p->setRelation('draft', null);
        }

        return $p;
    }

    public function test_failed_row_with_revivable_draft_is_eligible(): void
    {
        foreach (['scheduled', 'approved', 'awaiting_approval', 'failed'] as $draftStatus) {
            $e = ScheduledPostResource::rescheduleEligibility($this->makePost('failed', $draftStatus));
            $this->assertTrue($e['eligible'], "failed + draft={$draftStatus} should be eligible");
        }
    }

    public function test_cancelled_row_with_revivable_draft_is_eligible(): void
    {
        $e = ScheduledPostResource::rescheduleEligibility($this->makePost('cancelled', 'scheduled'));
        $this->assertTrue($e['eligible']);
    }

    public function test_already_published_draft_is_skipped_to_avoid_double_post(): void
    {
        // The exact double-post case: row was cancelled but the content went live.
        $e = ScheduledPostResource::rescheduleEligibility($this->makePost('cancelled', 'published'));
        $this->assertFalse($e['eligible']);
        $this->assertStringContainsStringIgnoringCase('publish', $e['reason']);
    }

    public function test_published_scheduled_post_is_skipped(): void
    {
        $e = ScheduledPostResource::rescheduleEligibility($this->makePost('published', 'published'));
        $this->assertFalse($e['eligible']);
    }

    public function test_in_flight_rows_are_left_untouched(): void
    {
        foreach (['queued', 'submitting', 'submitted'] as $status) {
            $e = ScheduledPostResource::rescheduleEligibility($this->makePost($status, 'scheduled'));
            $this->assertFalse($e['eligible'], "{$status} is in-flight and must not be disturbed");
        }
    }

    public function test_row_without_a_draft_is_skipped(): void
    {
        $e = ScheduledPostResource::rescheduleEligibility($this->makePost('failed', null));
        $this->assertFalse($e['eligible']);
        $this->assertStringContainsStringIgnoringCase('draft', $e['reason']);
    }

    /**
     * Source-level lock on the bulk action's revive semantics — the mutation is
     * DB-driven so we assert on the resource source (same convention as
     * [[PublishStatusCopyAndOrphanRecoveryTest]]).
     */
    public function test_bulk_action_revive_invariants(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ScheduledPostResource::class))->getFileName()
        );

        // The bulk action exists in the toolbar.
        $this->assertStringContainsString("BulkAction::make('rescheduleBulk')", $src);
        // It gates each row through the pure eligibility check.
        $this->assertStringContainsString('rescheduleEligibility(', $src);
        // Reviving sets the row back to queued and clears the failure residue so
        // the dispatcher treats it as a fresh attempt.
        $this->assertStringContainsString("'status' => 'queued'", $src);
        $this->assertStringContainsString("'attempt_count' => 0", $src);
        $this->assertStringContainsString("'last_error' => null", $src);
        $this->assertStringContainsString("'blotato_post_id' => null", $src);
        // The underlying draft is rolled to 'scheduled' so the publisher runs it.
        $this->assertStringContainsString("'status' => 'scheduled'", $src);
        // It reports what it skipped (transparency, never silent truncation).
        $this->assertStringContainsString('skipped', $src);
    }
}
