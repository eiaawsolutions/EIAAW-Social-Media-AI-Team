<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the DraftEditor save contract at the source level (a DB-backed feature
 * test is avoided because SMT's local .env points at Railway PROD): any caption
 * save MUST reset the draft to compliance_pending and re-run ComplianceAgent, so
 * an edited caption can never reach approval with stale compliance checks.
 *
 * Also guards the worker-timeout contract: @set_time_limit is fine here (FPM
 * page request, not a queued job) — but only the *guarded* @-form, never a bare
 * set_time_limit that would re-arm PHP's hard limit elsewhere.
 *
 * DB-FREE by design.
 */
class DraftEditorSaveContractTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(\App\Filament\Agency\Pages\DraftEditor::class))->getFileName();
        $this->assertNotFalse($file);

        return (string) file_get_contents($file);
    }

    public function test_save_resets_to_compliance_pending_and_reruns_compliance(): void
    {
        $src = $this->source();

        // Isolate save() to assert against the right method.
        preg_match('/public function save\(\): void.*?\n    \}/s', $src, $m);
        $save = $m[0] ?? '';
        $this->assertNotSame('', $save, 'could not isolate save()');

        $this->assertStringContainsString("'status' => 'compliance_pending'", $save,
            'save() must reset the draft to compliance_pending');
        $this->assertStringContainsString('ComplianceAgent', $save,
            'save() must re-run ComplianceAgent');
        $this->assertStringContainsString('@set_time_limit', $save,
            'compliance is synchronous in the FPM request — must lift the time limit (guarded form)');
    }

    public function test_save_blocks_a_scheduled_draft_to_avoid_stranding_a_queued_post(): void
    {
        $src = $this->source();
        // Editing an approved+scheduled draft would strand the queued post when
        // status resets — the page must refuse it.
        $this->assertStringContainsString('scheduledPosts', $src);
        $this->assertStringContainsStringIgnoringCase('Unschedule', $src);
    }

    public function test_no_unguarded_set_time_limit(): void
    {
        $src = $this->source();
        // Every set_time_limit must be the silenced @-form (the worker-timeout
        // contract); a bare call is the footgun that fataled the Railway worker.
        $this->assertSame(0, preg_match_all('/(?<!@)\bset_time_limit\(/', $src),
            'found a bare set_time_limit — use @set_time_limit');
        // And it does use the guarded form somewhere (compliance is synchronous).
        $this->assertGreaterThan(0, preg_match_all('/@set_time_limit\(/', $src));
    }
}
