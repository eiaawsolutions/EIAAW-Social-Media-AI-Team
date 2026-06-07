<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards MetricoolConnectionService::sync()'s upsert against BOTH duplicate-row
 * regressions it has historically suffered — they pull in opposite directions,
 * so the test pins the shape that satisfies both at once.
 *
 * History:
 *   1. (chips-doubled bug) upsert keyed on the full 3-tuple (brand, platform,
 *      handle). Metricool reports a different handle than legacy Blotato rows
 *      (e.g. a Facebook page id vs a display name), so it never matched and
 *      INSERTED a duplicate on every re-sync.
 *   2. (Re-check outage, 2026-06-07) the fix for #1 keyed on (brand, platform)
 *      ONLY and rewrote the handle of an arbitrary matching row in place. But the
 *      DB unique index is the 3 columns (brand_id, platform, platform_account_id),
 *      so rewriting one row's handle to a value a SIBLING row already owns threw
 *      SQLSTATE 23505 — surfaced to the customer as "Couldn't check right now".
 *
 * Correct shape (revoke-then-upsert):
 *   • Step 1: revoke every ACTIVE row for this (brand, platform) whose handle is
 *     NOT the one Metricool now reports — clears legacy/stale rows out of the way.
 *   • Step 2: updateOrCreate keyed on the FULL 3-column unique key, so it reuses
 *     the exact row for this account (reactivating a revoked one on reconnect) or
 *     inserts cleanly — never a duplicate (the index forbids it), never a
 *     collision (the match key IS the index).
 *
 * Source-inspection only — the unit suite runs against the live DB connection
 * (sqlite is commented out in phpunit.xml), so a row-writing test would pollute
 * prod. We assert on the service source instead.
 */
class MetricoolSyncUpsertKeyTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(
            app_path('Services/Metricool/MetricoolConnectionService.php')
        );
    }

    public function test_upsert_matches_on_the_full_three_column_unique_key(): void
    {
        $src = $this->source();

        // The match array (1st arg to updateOrCreate) MUST be the full unique key
        // (brand_id, platform, platform_account_id) so it can neither collide on
        // nor duplicate against the DB's 3-column unique index.
        $matchBlock = '';
        if (preg_match('/updateOrCreate\(\s*\[(.*?)\]/s', $src, $m)) {
            $matchBlock = $m[1];
        }
        $this->assertNotSame('', $matchBlock, 'Could not locate the updateOrCreate match block.');

        $this->assertStringContainsString("'brand_id'", $matchBlock,
            'The upsert match key must include brand_id.');
        $this->assertStringContainsString("'platform'", $matchBlock,
            'The upsert match key must include platform.');
        $this->assertStringContainsString("'platform_account_id'", $matchBlock,
            'The upsert match key MUST include platform_account_id so it equals the '
            . 'DB unique index (brand_id, platform, platform_account_id) — keying on '
            . '(brand, platform) only rewrites a sibling row\'s handle and collides (SQLSTATE 23505).');
    }

    public function test_sync_revokes_stale_handles_before_upserting(): void
    {
        $src = $this->source();

        // Step 1 of the collision-proof shape: before upserting, any ACTIVE row
        // for the same (brand, platform) whose handle differs from the one
        // Metricool now reports must be revoked. This clears legacy Blotato rows
        // (different handle) and previous accounts out of the target handle's way,
        // so the full-key upsert in step 2 can never hit a live collision.
        $this->assertMatchesRegularExpression(
            "/->where\('platform_account_id',\s*'!=',\s*\\\$handle\)\s*->where\('status',\s*'active'\)\s*->update\(\['status'\s*=>\s*'revoked'\]\)/s",
            $src,
            'sync() must revoke active rows whose handle != the reported handle BEFORE upserting (revoke-then-upsert).'
        );
    }

    public function test_sync_still_revokes_networks_no_longer_reported(): void
    {
        $src = $this->source();

        // The cross-network sweep that revokes connections for networks Metricool
        // no longer reports for this brand must remain — a disconnect in Metricool
        // is still reflected.
        $this->assertStringContainsString('whereNotIn', $src,
            'sync() must still revoke networks no longer reported (whereNotIn over the seen set).');
    }
}
