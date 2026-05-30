<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards MetricoolConnectionService::sync()'s upsert against the duplicate-row
 * regression.
 *
 * The bug: the upsert keyed on (brand_id, platform, platform_account_id). Because
 * Metricool reports a different handle than the legacy Blotato rows carried
 * (e.g. a Facebook page id vs the display name), updateOrCreate never matched the
 * existing row and INSERTED a duplicate on every re-sync — the connected-network
 * chips doubled in the wizard.
 *
 * The fix keys the upsert on (brand_id, platform) ONLY and self-heals any other
 * active row for the same (brand, platform).
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

    public function test_upsert_matches_on_brand_and_platform_only_not_handle(): void
    {
        $src = $this->source();

        // The match array (1st arg to updateOrCreate) must contain brand_id +
        // platform and must NOT contain platform_account_id as a match key.
        // We check the ordered structure: 'brand_id' ... 'platform' appears in a
        // match block that does not also pin platform_account_id before the
        // values array opens.
        $this->assertMatchesRegularExpression(
            "/updateOrCreate\(\s*\[\s*'brand_id'\s*=>[^\]]*?'platform'\s*=>[^\]]*?\]/s",
            $src,
            'updateOrCreate must match on (brand_id, platform).'
        );

        // platform_account_id must NOT be a match key. It legitimately appears in
        // the VALUES array, so we specifically forbid it inside the match block:
        // i.e. between "updateOrCreate(" and the first "],".
        $matchBlock = '';
        if (preg_match('/updateOrCreate\(\s*\[(.*?)\]/s', $src, $m)) {
            $matchBlock = $m[1];
        }
        $this->assertNotSame('', $matchBlock, 'Could not locate the updateOrCreate match block.');
        $this->assertStringNotContainsString(
            'platform_account_id',
            $matchBlock,
            'platform_account_id must NOT be part of the upsert match key (it changed between Blotato and Metricool, which caused duplicate inserts).'
        );
    }

    public function test_sync_self_heals_other_active_rows_for_same_brand_platform(): void
    {
        $src = $this->source();

        // After the upsert, any OTHER active row for the same (brand, platform)
        // must be revoked, so exactly one active connection per network survives
        // even when a legacy duplicate already exists.
        $this->assertStringContainsString('whereKeyNot', $src,
            'sync() must revoke other active rows for the same (brand, platform).');
        // The self-heal block must: scope to the same brand+platform, exclude the
        // kept row, target active rows, and revoke them.
        $this->assertMatchesRegularExpression(
            "/whereKeyNot\(.*?->where\('status',\s*'active'\)\s*->update\(\['status'\s*=>\s*'revoked'\]\)/s",
            $src,
            'The self-heal must revoke sibling active rows (whereKeyNot → active → revoked).'
        );
    }
}
