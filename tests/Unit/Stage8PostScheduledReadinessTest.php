<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the Setup-Wizard Stage 8 ("First post scheduled") dead-end that
 * stranded the first paying Metricool customer (The Bear Hug, brand #8) right
 * after Stage 7 cleared.
 *
 * Symptom: Stage 7 ("First draft passes Compliance") showed green, but Stage 8
 * was permanently blocked ("finish stage 7 first") and could not be actioned —
 * readiness sat at 77% with nextActionable reporting "complete".
 *
 * Root cause — detector inconsistency between two adjacent stages:
 *   stage7_complianceApprovedDraft treats a draft as PASSED when its status is
 *   awaiting_approval / approved / scheduled / published.
 *   stage8_postScheduled checked its prerequisite ("a draft passed Compliance")
 *   with ['approved', 'scheduled', 'published'] — EXCLUDING awaiting_approval.
 *   On the amber/red lanes a passed draft sits at awaiting_approval and never
 *   reaches 'approved' until Stage 8 itself approves it (runFirstSchedule()
 *   explicitly accepts awaiting_approval). So the gate blocked the very action
 *   that would satisfy it.
 *
 * Fix: Stage 8's prerequisite must use the SAME status set Stage 7 uses to call
 * a draft "passed" — including awaiting_approval.
 *
 * Source-inspection only — the unit suite runs against the live DB connection
 * (sqlite is commented out in phpunit.xml), so a row-writing test would pollute
 * prod. We assert on the source instead, mirroring Stage4PlatformConnectedReadinessTest.
 *
 * @see [[no_trial_blotato_handoff]] [[designer_blotato_metricool_gap]]
 */
class Stage8PostScheduledReadinessTest extends TestCase
{
    private function readinessSource(): string
    {
        return file_get_contents(app_path('Services/Readiness/SetupReadiness.php'));
    }

    /** Isolate a detector method body so assertions target the right stage. */
    private function methodBody(string $method): string
    {
        $src = $this->readinessSource();
        $this->assertTrue(
            (bool) preg_match(
                '/function ' . preg_quote($method, '/') . '\(.*?\)\s*:\s*ReadinessStage\s*\{(.*?)\n    \}/s',
                $src,
                $m,
            ),
            "Could not isolate {$method}() body.",
        );

        return $m[1];
    }

    public function test_stage8_prerequisite_accepts_awaiting_approval(): void
    {
        $body = $this->methodBody('stage8_postScheduled');

        // The prerequisite (blockedBy='first_draft_passed') existence check must
        // include awaiting_approval — otherwise amber/red-lane customers, whose
        // passed drafts sit at awaiting_approval, can never action Stage 8.
        $this->assertStringContainsString(
            "'awaiting_approval'",
            $body,
            'Stage 8 must treat an awaiting_approval draft as a satisfied '
            . '"first_draft_passed" prerequisite — on amber/red lanes that is the '
            . 'only state a passed draft reaches until Stage 8 approves it.',
        );
    }

    public function test_stage8_prerequisite_matches_stage7_passed_definition(): void
    {
        $stage7 = $this->methodBody('stage7_complianceApprovedDraft');
        $stage8 = $this->methodBody('stage8_postScheduled');

        // Extract the status array Stage 7 uses to define a DRAFT as "passed".
        // Stage 7 has two whereIn('status', …) calls — the calendar gate
        // (['in_review', 'approved']) and the draft gate. Target the draft set
        // by anchoring on 'awaiting_approval', which only the draft set carries.
        $this->assertTrue(
            (bool) preg_match("/whereIn\('status',\s*(\['awaiting_approval'[^\]]*\])\)/", $stage7, $m7),
            'Could not find the passed-draft status set in stage 7.',
        );
        $stage7Set = $m7[1];

        // Stage 8's prerequisite check must use the identical set, so the two
        // adjacent gates never contradict each other again.
        $this->assertStringContainsString(
            $stage7Set,
            $stage8,
            "Stage 8's prerequisite status set must match Stage 7's passed-status "
            . "set verbatim ({$stage7Set}) so a draft Stage 7 calls 'passed' is never "
            . 'treated by Stage 8 as a missing prerequisite.',
        );
    }
}
