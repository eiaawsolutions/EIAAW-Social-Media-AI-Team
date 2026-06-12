<?php

namespace Tests\Unit;

use App\Services\Imagery\CustomisedPostScheduler;
use Tests\TestCase;

/**
 * DB-free unit coverage for the customised-post platform gate. The scheduler's
 * write path needs the (prod) DB, so we only assert the pure logic here, in
 * line with the project's "keep tests DB-free" constraint.
 */
class CustomisedPostSchedulerTest extends TestCase
{
    public function test_lowercases_and_dedupes_platforms(): void
    {
        $this->assertSame(
            ['instagram', 'facebook'],
            CustomisedPostScheduler::normalisePlatforms(['Instagram', ' FACEBOOK ', 'instagram']),
        );
    }

    public function test_drops_unsupported_platforms(): void
    {
        $this->assertSame(
            ['linkedin'],
            CustomisedPostScheduler::normalisePlatforms(['linkedin', 'myspace', 'snapchat', '']),
        );
    }

    public function test_empty_when_nothing_valid(): void
    {
        $this->assertSame([], CustomisedPostScheduler::normalisePlatforms(['bebo', 'orkut']));
    }

    public function test_all_supported_platforms_pass_through(): void
    {
        $all = CustomisedPostScheduler::SUPPORTED_PLATFORMS;
        $this->assertSame($all, CustomisedPostScheduler::normalisePlatforms($all));
    }

    public function test_x_is_supported_but_twitter_alias_is_not_silently_accepted(): void
    {
        // We store 'x' (modern brand); 'twitter' is mapped to 'x' only at the
        // Blotato boundary, not here — so a raw 'twitter' input is rejected to
        // avoid a platform the connection layer doesn't expect.
        $this->assertSame(['x'], CustomisedPostScheduler::normalisePlatforms(['x', 'twitter']));
    }

    /**
     * Regression guard for "Could not schedule the customised post —
     * SQLSTATE[23502] Not null violation: pillar_mix".
     *
     * content_calendars.pillar_mix and .format_mix are NOT NULL (the Strategist
     * agent fills them for generated calendars). The reusable "Customised posts"
     * calendar is created by the scheduler's firstOrCreate and carries no
     * pillar/format strategy — so the scheduler MUST supply non-null values for
     * every NOT-NULL *_mix column, or the INSERT 23502s.
     *
     * This test reads the NOT-NULL *_mix columns straight out of the migration
     * and asserts the scheduler's customisedCalendar() create block supplies each
     * one — so adding a new NOT-NULL *_mix column later fails here until the
     * scheduler is updated. DB-free by design (local .env DB == prod).
     */
    public function test_customised_calendar_supplies_every_not_null_mix_column(): void
    {
        $migration = (string) file_get_contents(
            base_path('database/migrations/2026_04_30_100400_create_content_tables.php'),
        );

        // Isolate the content_calendars Schema::create block.
        $this->assertSame(
            1,
            preg_match(
                "/Schema::create\('content_calendars'.*?\}\);/s",
                $migration,
                $block,
            ),
            'Could not locate the content_calendars schema block.',
        );
        $calendarSchema = $block[0];

        // A *_mix column is NOT NULL unless the same statement chains ->nullable().
        preg_match_all(
            "/->(?:json|jsonb)\('([a-z_]+_mix)'\)([^;]*);/",
            $calendarSchema,
            $mixCols,
            PREG_SET_ORDER,
        );
        $notNullMix = [];
        foreach ($mixCols as $m) {
            [$full, $name, $chain] = $m;
            if (! str_contains($chain, '->nullable(')) {
                $notNullMix[] = $name;
            }
        }

        // Sanity: the migration really does have NOT-NULL *_mix columns to guard.
        $this->assertContains('pillar_mix', $notNullMix, 'Expected pillar_mix to be NOT NULL in the migration.');

        // The scheduler's customised-calendar create block must supply each one.
        $scheduler = (string) file_get_contents(
            app_path('Services/Imagery/CustomisedPostScheduler.php'),
        );
        $this->assertSame(
            1,
            preg_match('/function customisedCalendar\(.*?\n    \}/s', $scheduler, $fn),
            'Could not locate customisedCalendar() in the scheduler.',
        );
        $createBlock = $fn[0];

        foreach ($notNullMix as $col) {
            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($col, '/') . "'\s*=>/",
                $createBlock,
                "customisedCalendar() must supply a non-null '{$col}' (it is NOT NULL in content_calendars) "
                . 'or the INSERT fails with SQLSTATE 23502.',
            );
        }
    }

    /**
     * createDraft() must supply model_id (NOT NULL on the drafts table with no
     * default — see migration 2026_04_30_100400 `$table->string('model_id')`).
     * Omitting it 23502s every customised-draft insert and rolls back the whole
     * schedule() transaction (the bug that left zero customised drafts in prod).
     * DB-free by design (local .env DB == prod).
     */
    public function test_create_draft_supplies_not_null_model_id(): void
    {
        $block = $this->createDraftBlock();
        $this->assertMatchesRegularExpression(
            "/'model_id'\s*=>/",
            $block,
            'createDraft() must set model_id — it is NOT NULL on drafts, omitting it 23502s the insert.',
        );
    }

    /**
     * Customised drafts must ENTER the compliance gate (compliance_pending), not
     * be created pre-approved. Compliance now runs the same 7 checks as agent
     * posts and sets the landing status itself.
     */
    public function test_create_draft_enters_compliance_pending_not_approved(): void
    {
        $block = $this->createDraftBlock();
        $this->assertMatchesRegularExpression(
            "/'status'\s*=>\s*'compliance_pending'/",
            $block,
            "createDraft() must create the draft at 'compliance_pending' so it runs through compliance.",
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'status'\s*=>\s*'approved'/",
            $block,
            "createDraft() must NOT pre-approve — that would skip the compliance gate.",
        );
    }

    /**
     * Approval columns are stamped by ComplianceAgent (green) or a human
     * (amber/red), never at draft creation. Stamping them here would be false
     * provenance and uses auth()->id(), which is null in the CLI recovery path.
     */
    public function test_create_draft_does_not_stamp_approval_at_creation(): void
    {
        $block = $this->createDraftBlock();
        $this->assertStringNotContainsString('approved_by_user_id', $block,
            'createDraft() must not set approved_by_user_id — approval happens after compliance.');
        $this->assertStringNotContainsString('approved_at', $block,
            'createDraft() must not set approved_at — approval happens after compliance.');
    }

    /**
     * Compliance must run AFTER the write transaction commits — ComplianceAgent
     * does LLM + pgvector work that must not hold a write lock, and a mid-LLM
     * failure must not roll back the valid calendar/draft rows.
     */
    public function test_compliance_runs_after_the_transaction_commits(): void
    {
        $src = $this->schedulerSource();
        $txPos = strpos($src, 'DB::transaction(');
        $compliancePos = strpos($src, 'runComplianceSafely(');
        $this->assertNotFalse($txPos, 'Expected a DB::transaction block in schedule().');
        $this->assertNotFalse($compliancePos, 'Expected a runComplianceSafely() call.');
        $this->assertGreaterThan(
            $txPos,
            $compliancePos,
            'runComplianceSafely() must be called AFTER the DB::transaction block, not inside it.',
        );
        $this->assertStringContainsString('ComplianceAgent::class', $src,
            'schedule() must invoke the ComplianceAgent.');
    }

    /**
     * A brand with no current brand_style makes ComplianceAgent throw
     * AgentPrerequisiteMissing. We must catch it and PARK the draft at
     * awaiting_approval (manual review) — never auto-publish ungoverned copy.
     */
    public function test_compliance_no_style_holds_for_manual_review(): void
    {
        $src = $this->schedulerSource();
        $this->assertStringContainsString('AgentPrerequisiteMissing', $src,
            'runComplianceSafely() must catch AgentPrerequisiteMissing (no brand_style).');
        $this->assertMatchesRegularExpression(
            "/'status'\s*=>\s*'awaiting_approval'/",
            $src,
            'A brand with no style must hold the draft at awaiting_approval, not auto-approve.',
        );
    }

    /**
     * The auto-redraft loop must NEVER rewrite operator-authored copy. Guard it
     * at both layers: the DraftsRedraftFailed cron query (so the job is never
     * dispatched) and the RedraftFailedDraft job itself (defensive).
     */
    public function test_redraft_cron_excludes_operator_drafts(): void
    {
        $src = (string) file_get_contents(app_path('Console/Commands/DraftsRedraftFailed.php'));
        $this->assertMatchesRegularExpression(
            "/->where\(\s*'agent_role'\s*,\s*'!='\s*,\s*'operator'\s*\)/",
            $src,
            'DraftsRedraftFailed must exclude agent_role=operator from its pick query.',
        );
        $this->assertMatchesRegularExpression(
            "/->where\(\s*'prompt_version'\s*,\s*'!='\s*,\s*'customised-post\.v1'\s*\)/",
            $src,
            'DraftsRedraftFailed must exclude prompt_version=customised-post.v1 from its pick query.',
        );
    }

    public function test_redraft_job_has_defensive_operator_guard(): void
    {
        $src = (string) file_get_contents(app_path('Jobs/RedraftFailedDraft.php'));
        $this->assertMatchesRegularExpression(
            "/agent_role\s*===\s*'operator'/",
            $src,
            'RedraftFailedDraft::handle() must early-return on operator-authored drafts.',
        );
        $this->assertStringContainsString("'customised-post.v1'", $src,
            'RedraftFailedDraft::handle() must also guard on the customised-post.v1 prompt version.');
    }

    /** The recovery command exposes the expected signature + options. */
    public function test_recovery_command_signature(): void
    {
        $src = (string) file_get_contents(app_path('Console/Commands/PostsRescheduleOrphanedCustomised.php'));
        $this->assertStringContainsString('posts:reschedule-orphaned-customised', $src);
        foreach (['--asset', '--workspace', '--platforms', '--narrative', '--publish-in-minutes', '--dry-run'] as $opt) {
            // signatures declare options as `{--name=...}` / `{--name : ...}`.
            $this->assertStringContainsString(ltrim($opt, '-'), $src,
                "Recovery command should declare the {$opt} option.");
        }
    }

    /**
     * Tenant safety: --workspace must scope the orphan query to one workspace's
     * brands (the command is a CLI with no auth context, so the unfiltered query
     * spans all customers). And it must warn when run unscoped.
     */
    public function test_recovery_command_supports_workspace_scope(): void
    {
        $src = (string) file_get_contents(app_path('Console/Commands/PostsRescheduleOrphanedCustomised.php'));
        $this->assertMatchesRegularExpression(
            "/whereHas\(\s*'brand'.*workspace_id/s",
            $src,
            '--workspace must scope via the brand workspace_id.',
        );
        $this->assertMatchesRegularExpression(
            '/across ALL workspaces/i',
            $src,
            'Running unscoped must warn that it sweeps all workspaces.',
        );
    }

    /**
     * Pre-flight: the command must warn about platforms with no active
     * connection (those drafts land 'approved' but never auto-queue), and treat
     * AlreadyScheduledException as a benign skip, not a hard error.
     */
    public function test_recovery_command_preflight_and_benign_skip(): void
    {
        $src = (string) file_get_contents(app_path('Console/Commands/PostsRescheduleOrphanedCustomised.php'));
        $this->assertStringContainsString('PlatformConnection::where', $src,
            'Command must pre-flight active platform connections.');
        $this->assertStringContainsString("->where('status', 'active')", $src);
        $this->assertStringContainsString('catch (AlreadyScheduledException', $src,
            'Command must treat AlreadyScheduledException as a benign skip.');
    }

    /**
     * The upload handler must not show a flat "Scheduled!" success when compliance
     * held or failed a customised post — it must inspect the returned draft
     * statuses and branch (success / warning on failed / info on awaiting).
     */
    public function test_upload_handler_notification_is_status_aware(): void
    {
        $src = (string) file_get_contents(
            app_path('Filament/Agency/Resources/BrandAssets/Pages/ManageBrandAssets.php'),
        );
        // It buckets by the live draft status rather than assuming success.
        $this->assertStringContainsString("countBy(fn (\$d) => \$d->status)", $src,
            'Handler must count drafts by their (refreshed) status.');
        $this->assertStringContainsString('compliance_failed', $src,
            'Handler must detect compliance_failed drafts.');
        $this->assertStringContainsString('awaiting_approval', $src,
            'Handler must detect awaiting_approval drafts.');
        // A double-submit surfaces calmly, not as a red error.
        $this->assertStringContainsString('AlreadyScheduledException', $src,
            'Handler must catch AlreadyScheduledException as a benign warning.');
    }

    /**
     * createEntry() must set a calendar_entries.status that the CHECK-constrained
     * enum actually permits. The enum is ['planned','drafted','scheduled',
     * 'published','skipped'] — 'approved' is NOT a member (it belongs to the
     * content_calendars + drafts vocabularies), and using it 23514s the INSERT,
     * rolling back the whole schedule() transaction. This reads the allowed set
     * straight from the migration and asserts createEntry()'s status is in it, so
     * a future drift to an out-of-vocabulary value fails here. DB-free by design.
     */
    public function test_create_entry_status_is_a_valid_calendar_entry_enum_member(): void
    {
        $migration = (string) file_get_contents(
            base_path('database/migrations/2026_04_30_100400_create_content_tables.php'),
        );

        // Isolate the calendar_entries Schema::create block, then its status enum.
        $this->assertSame(
            1,
            preg_match("/Schema::create\('calendar_entries'.*?\}\);/s", $migration, $block),
            'Could not locate the calendar_entries schema block.',
        );
        $this->assertSame(
            1,
            preg_match("/enum\('status',\s*\[([^\]]*)\]\)/", $block[0], $enum),
            'Could not locate the calendar_entries status enum.',
        );
        preg_match_all("/'([a-z_]+)'/", $enum[1], $vals);
        $allowed = $vals[1];
        $this->assertContains('scheduled', $allowed, 'Sanity: enum should include scheduled.');
        $this->assertNotContains('approved', $allowed, 'Sanity: enum must NOT include approved (the bug value).');

        // Extract createEntry()'s status value and assert it's an allowed member.
        $entryBlock = $this->createEntryBlock();
        $this->assertSame(
            1,
            preg_match("/'status'\s*=>\s*'([a-z_]+)'/", $entryBlock, $m),
            'Could not find a literal status in createEntry().',
        );
        $this->assertContains(
            $m[1],
            $allowed,
            "createEntry() sets calendar_entries.status='{$m[1]}', which the CHECK enum rejects — INSERT will 23514.",
        );
    }

    /**
     * Concurrency guard: schedule() must re-read the asset FOR UPDATE inside the
     * transaction and throw AlreadyScheduledException if it already has a
     * customised_calendar_entry_id — otherwise two concurrent callers (a
     * double-click, two recovery workers) both create a calendar entry + drafts
     * and orphan the first set.
     */
    public function test_schedule_guards_against_already_scheduled_asset(): void
    {
        $src = $this->schedulerSource();
        $this->assertStringContainsString('lockForUpdate()', $src,
            'schedule() must lock the asset row to serialise concurrent callers.');
        $this->assertStringContainsString('AlreadyScheduledException', $src,
            'schedule() must throw AlreadyScheduledException when the asset is already scheduled.');
        // The guard must read customised_calendar_entry_id under the lock.
        $this->assertMatchesRegularExpression(
            '/lockForUpdate\(\).*?customised_calendar_entry_id/s',
            $src,
            'The lock must be taken before the already-scheduled check.',
        );
        $this->assertTrue(
            class_exists(\App\Exceptions\AlreadyScheduledException::class),
            'AlreadyScheduledException class must exist.',
        );
    }

    /**
     * When compliance can't run (no brand_style) or errors, the draft must NOT
     * be held with a blank Drafts page — runComplianceSafely() records an
     * explanatory ComplianceCheck (result='error') so the operator sees why.
     */
    public function test_compliance_hold_records_an_audit_row(): void
    {
        $src = $this->schedulerSource();
        $this->assertStringContainsString('recordComplianceHold(', $src,
            'runComplianceSafely() must record an audit row on the hold paths.');
        $this->assertStringContainsString('ComplianceCheck::create', $src,
            'recordComplianceHold() must create a ComplianceCheck row.');
        $this->assertMatchesRegularExpression(
            "/'result'\s*=>\s*'error'/",
            $src,
            "The audit row must use result='error' (a valid compliance_checks enum value).",
        );
        $this->assertMatchesRegularExpression(
            "/'checked_at'\s*=>\s*now\(\)/",
            $src,
            'compliance_checks.checked_at is NOT NULL — recordComplianceHold() must supply it.',
        );
    }

    /**
     * The returned drafts must reflect the TRUE post-compliance status (the
     * caller's notification reads it). Without a refresh they are the stale
     * 'compliance_pending' snapshot from createDraft(), producing a false
     * "Scheduled!" toast even when compliance held or failed the post.
     */
    public function test_runComplianceSafely_refreshes_drafts(): void
    {
        $src = $this->schedulerSource();
        $this->assertMatchesRegularExpression(
            '/\$draft->refresh\(\)/',
            $src,
            'runComplianceSafely() must refresh each draft so the returned status is live.',
        );
    }

    /**
     * The catch-block status update must be defensive — a failure there must not
     * propagate out of schedule() and leave the draft stranded in
     * compliance_pending (invisible to every downstream cron).
     */
    public function test_status_update_in_hold_path_is_defensive(): void
    {
        $src = $this->schedulerSource();
        // recordComplianceHold wraps the update in its own try/catch.
        $this->assertSame(
            1,
            preg_match('/function recordComplianceHold\(.*?\n    \}/s', $src, $fn),
            'Could not locate recordComplianceHold().',
        );
        $this->assertStringContainsString('try {', $fn[0]);
        $this->assertMatchesRegularExpression(
            "/'status'\s*=>\s*'awaiting_approval'/",
            $fn[0],
            'recordComplianceHold() parks the draft at awaiting_approval.',
        );
    }

    /** Helper: isolate the createEntry() method body from the scheduler source. */
    private function createEntryBlock(): string
    {
        $src = $this->schedulerSource();
        $this->assertSame(
            1,
            preg_match('/function createEntry\(.*?\n    \}/s', $src, $fn),
            'Could not locate createEntry() in the scheduler.',
        );

        return $fn[0];
    }

    /** Helper: isolate the createDraft() method body from the scheduler source. */
    private function createDraftBlock(): string
    {
        $src = $this->schedulerSource();
        $this->assertSame(
            1,
            preg_match('/function createDraft\(.*?\n    \}/s', $src, $fn),
            'Could not locate createDraft() in the scheduler.',
        );

        return $fn[0];
    }

    private function schedulerSource(): string
    {
        return (string) file_get_contents(app_path('Services/Imagery/CustomisedPostScheduler.php'));
    }
}
