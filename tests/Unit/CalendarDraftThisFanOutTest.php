<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * DB-free regression guard for: "after editing the calendar direction and
 * clicking 'Draft this', it doesn't draft for ALL the platforms selected".
 *
 * Root cause: the per-row "Draft this" (runWriter) action drafted only the
 * FIRST listed platform —
 *     $platform = $platforms[0] ?? null;   // single platform, synchronous
 * — so an entry with e.g. youtube/linkedin/facebook produced exactly one
 * draft. The edit-then-draft flow expects a draft for EVERY selected platform.
 *
 * Fix: "Draft this" now fans out one DraftCalendarEntry job per listed
 * platform (the same proven, idempotent, cap-respecting path as the header
 * "Draft all" and the per-row "Re-evaluate").
 *
 * This guard reads the resource source and fails if "Draft this" ever
 * regresses to single-platform indexing, and confirms "Re-evaluate" clears
 * HELD drafts (not just rejected) so it can genuinely force a re-draft. It is
 * source-level + DB-free by design (local .env DB == prod — never write rows).
 */
class CalendarDraftThisFanOutTest extends TestCase
{
    private function resourceSource(): string
    {
        $path = app_path('Filament/Agency/Resources/CalendarEntries/CalendarEntryResource.php');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Isolate the runWriter ("Draft this") action block. */
    private function runWriterActionSource(): string
    {
        $source = $this->resourceSource();
        // From `Action::make('runWriter')` up to the next `Action::make(` call.
        if (! preg_match("/Action::make\('runWriter'\).*?(?=Action::make\(')/s", $source, $m)) {
            $this->fail("Could not isolate the 'runWriter' action in CalendarEntryResource.");
        }

        return $m[0];
    }

    /** Isolate the reEvaluate ("Re-evaluate") action block. */
    private function reEvaluateActionSource(): string
    {
        $source = $this->resourceSource();
        if (! preg_match("/Action::make\('reEvaluate'\).*?(?=Action::make\(')/s", $source, $m)) {
            $this->fail("Could not isolate the 'reEvaluate' action in CalendarEntryResource.");
        }

        return $m[0];
    }

    public function test_draft_this_fans_out_one_job_per_platform(): void
    {
        $action = $this->runWriterActionSource();

        // Must loop over the entry's platforms...
        $this->assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$platforms\s+as\s+\$platform\s*\)/',
            $action,
            '"Draft this" must iterate every listed platform, not just the first.',
        );

        // ...and dispatch a DraftCalendarEntry job for each onto the drafting queue.
        $this->assertMatchesRegularExpression(
            '/DraftCalendarEntry::dispatch\(\s*\$r->id\s*,\s*\$platform\s*\)/',
            $action,
            '"Draft this" must dispatch one DraftCalendarEntry job per (entry, platform).',
        );
        $this->assertStringContainsString(
            "->onQueue('drafting')",
            $action,
            'Fan-out jobs must land on the drafting queue, like "Draft all" / "Re-evaluate".',
        );
    }

    public function test_draft_this_does_not_regress_to_single_platform_indexing(): void
    {
        $action = $this->runWriterActionSource();

        // The bug was selecting one platform by index. Guard against its return.
        $this->assertSame(
            0,
            preg_match('/\$platform\s*=\s*\$platforms\[0\]/', $action),
            '"Draft this" must NOT pick a single platform via $platforms[0] — that drafted '
            . 'only the first platform and is the exact bug this test locks.',
        );

        // And it must not run the agent chain synchronously in-request anymore
        // (which both single-platformed AND risked the 180s execution wall).
        $this->assertSame(
            0,
            preg_match('/app\(\\\\App\\\\Agents\\\\WriterAgent::class\)/', $action),
            '"Draft this" must dispatch jobs, not call WriterAgent synchronously in the '
            . 'panel request (avoids the 180s wall and the single-platform bug).',
        );
    }

    public function test_draft_this_is_idempotent_skipping_already_drafted_platforms(): void
    {
        $action = $this->runWriterActionSource();

        // It should skip platforms that already have a non-rejected draft so a
        // re-click after a partial run doesn't duplicate work.
        $this->assertMatchesRegularExpression(
            "/whereNotIn\(\s*'status'\s*,\s*\[\s*'rejected'\s*\]\s*\)/",
            $action,
            '"Draft this" must skip platforms that already have a non-rejected draft (idempotent fill).',
        );
    }

    public function test_re_evaluate_clears_held_drafts_not_just_rejected(): void
    {
        $action = $this->reEvaluateActionSource();

        // "Re-evaluate" promises a FRESH re-draft. Because DraftCalendarEntry
        // skips any non-rejected existing draft, clearing only 'rejected' made it
        // a no-op for held drafts. It must clear the held states too.
        foreach (['rejected', 'compliance_pending', 'compliance_failed', 'awaiting_approval'] as $heldStatus) {
            $this->assertStringContainsString(
                "'{$heldStatus}'",
                $action,
                "\"Re-evaluate\" must clear '{$heldStatus}' drafts so the re-draft actually runs.",
            );
        }

        // Live drafts must NOT be in the clear list (work in flight is preserved).
        $this->assertSame(
            0,
            preg_match("/->whereIn\(\s*'status'\s*,\s*\[[^\]]*'(scheduled|published)'/s", $action),
            '"Re-evaluate" must NOT delete scheduled/published drafts — only non-live ones.',
        );
    }
}
