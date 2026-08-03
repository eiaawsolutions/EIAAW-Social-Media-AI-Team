<?php

namespace Tests\Unit;

use App\Models\ContentCalendar;
use App\Services\Imagery\CustomisedPostScheduler;
use Tests\TestCase;

/**
 * Regression cover for the 2026-07-17 → 2026-08-03 brand #1 standstill.
 *
 * What happened: an operator scheduled ONE asset as a daily recurring customised
 * post — 52 occurrences (the RecurrenceExpander cap) across all 6 platforms,
 * claiming every calendar day from 2026-07-18 to 2026-09-07.
 *
 * The first occurrence failed compliance on a deterministic, reproducible input
 * error (the post body had been captured into hashtag #3, 110 chars). Two
 * separate defects then turned that ordinary content mistake into seven weeks of
 * total silence on a fully-autonomous brand:
 *
 *   A. cloneComplianceVerdict() collapsed the failure to 'awaiting_approval' for
 *      all 306 follower drafts — discarding the reason (they were written with
 *      ZERO compliance_check rows) and parking them behind a human approval gate
 *      that a GREEN-lane brand does not have by definition.
 *
 *   B. ContentAutopilot counted those operator entries as autonomous plan
 *      coverage. upcomingEntryCount() saw a full window and never ran the
 *      Strategist; dispatchDrafts() saw a non-rejected draft on every
 *      (entry, platform) pair and dispatched nothing. The hourly cron logged a
 *      clean "0 built, 0 dispatched" while the brand went dark.
 *
 * These tests are DB-free by design (local .env DB == prod — keep tests DB-free),
 * so they cover the two pure decision functions the fix extracted, plus a
 * source-level guard that the autopilot's entry queries stay calendar-scoped.
 */
class CustomisedPostDeadlockTest extends TestCase
{
    // ── Defect A: the follower verdict must not launder a failure ──────────

    public function test_follower_inherits_a_real_compliance_failure(): void
    {
        // THE regression. Before the fix this returned 'awaiting_approval',
        // which is what silently stranded 277 drafts on a green-lane brand.
        $this->assertSame(
            'compliance_failed',
            CustomisedPostScheduler::inheritedStatusFor('compliance_failed'),
        );
    }

    public function test_follower_inherits_a_pass_verbatim(): void
    {
        $this->assertSame('approved', CustomisedPostScheduler::inheritedStatusFor('approved'));
        $this->assertSame('scheduled', CustomisedPostScheduler::inheritedStatusFor('scheduled'));
    }

    public function test_follower_inherits_an_amber_lane_hold(): void
    {
        // An amber-lane brand's passing draft legitimately waits for a human.
        $this->assertSame(
            'awaiting_approval',
            CustomisedPostScheduler::inheritedStatusFor('awaiting_approval'),
        );
    }

    public function test_unknown_or_missing_verdict_fails_safe_to_the_human_gate(): void
    {
        // Compliance never wrote a verdict — the run died before it got there.
        // "We don't know" is the ONE case where a person genuinely has to look,
        // so this must never auto-queue and must never claim a failure it
        // didn't observe.
        $this->assertSame('awaiting_approval', CustomisedPostScheduler::inheritedStatusFor(null));
        $this->assertSame('awaiting_approval', CustomisedPostScheduler::inheritedStatusFor('compliance_pending'));
        $this->assertSame('awaiting_approval', CustomisedPostScheduler::inheritedStatusFor('published'));
        $this->assertSame('awaiting_approval', CustomisedPostScheduler::inheritedStatusFor('rejected'));
    }

    public function test_a_failure_is_never_laundered_into_an_auto_queueing_status(): void
    {
        // Belt-and-braces: whatever the source status, a follower may never come
        // out of this function in a state that auto-queues unless the source
        // itself passed. Guards a future edit that adds a new passing status.
        foreach ([null, 'compliance_pending', 'compliance_failed', 'awaiting_approval', 'rejected', 'archived'] as $notPassing) {
            $this->assertNotContains(
                CustomisedPostScheduler::inheritedStatusFor($notPassing),
                ['approved', 'scheduled'],
                "source status '".var_export($notPassing, true)."' must not yield an auto-queueing verdict",
            );
        }
    }

    // ── Defect B: operator posts are additive, never plan coverage ─────────

    public function test_customised_calendar_is_not_autonomous(): void
    {
        $this->assertFalse(ContentCalendar::isAutonomousLabel(ContentCalendar::CUSTOMISED_LABEL));
    }

    public function test_strategist_calendars_are_autonomous(): void
    {
        $this->assertTrue(ContentCalendar::isAutonomousLabel('August 2026'));
        $this->assertTrue(ContentCalendar::isAutonomousLabel('Q3 always-on'));
        $this->assertTrue(ContentCalendar::isAutonomousLabel(null));
    }

    public function test_scheduler_and_autopilot_agree_on_the_customised_label(): void
    {
        // The label is the discriminator between "operator shelf" and "the plan".
        // If CustomisedPostScheduler ever writes a different literal, autopilot
        // silently starts counting operator posts as plan coverage again — which
        // is exactly the deadlock. Assert the scheduler routes through the
        // constant rather than re-declaring the string.
        $src = (string) file_get_contents(
            base_path('app/Services/Imagery/CustomisedPostScheduler.php'),
        );

        $this->assertStringContainsString(
            'ContentCalendar::CUSTOMISED_LABEL',
            $src,
            'CustomisedPostScheduler must resolve the customised calendar via the shared constant.',
        );
        $this->assertStringNotContainsString(
            "'label' => 'Customised posts'",
            $src,
            'CustomisedPostScheduler must not hard-code the customised calendar label.',
        );
    }

    public function test_autopilot_scopes_both_entry_queries_to_autonomous_calendars(): void
    {
        // Source-level guard, in the spirit of the migration-reading test in
        // CustomisedPostSchedulerTest: the deadlock returns the moment either
        // query stops filtering by content_calendar_id. Both the coverage signal
        // (upcomingEntryCount) and the drafting pass (dispatchDrafts) must scope.
        $src = (string) file_get_contents(
            base_path('app/Console/Commands/ContentAutopilot.php'),
        );

        $this->assertSame(
            2,
            substr_count($src, "whereIn('content_calendar_id', \$calendarIds)"),
            'ContentAutopilot must scope BOTH upcomingEntryCount() and dispatchDrafts() '
                .'to the brand\'s autonomous calendars — otherwise operator customised '
                .'posts starve the autonomous plan (the 2026-07-17 deadlock).',
        );

        $this->assertStringContainsString(
            '->autonomous()',
            $src,
            'ContentAutopilot must resolve its calendar ids through the autonomous() scope.',
        );
    }
}
