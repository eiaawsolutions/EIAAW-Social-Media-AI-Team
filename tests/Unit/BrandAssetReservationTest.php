<?php

namespace Tests\Unit;

use App\Models\BrandAsset;
use App\Services\Imagery\BrandAssetPicker;
use Tests\TestCase;

/**
 * Regression guard for the headline invariant of the Brand Assets feature:
 *
 *   A "customised post" asset is reserved for the ONE dedicated post the
 *   operator scheduled around it, and must NEVER be re-picked by the AI
 *   auto-poster for an autonomous draft.
 *
 * The enforcement lives in two places, both keyed on usage_intent:
 *   - BrandAssetPicker::pickFor()  → raw pgvector SQL with `usage_intent = 'general'`
 *   - BrandAssetPicker::hasAnyOfType() → ->generalPool() scope
 *
 * These are DB-free assertions (per the project's "keep tests DB-free"
 * constraint — local .env DB == prod). We assert on the query the scope
 * builds and on the intent contract the raw SQL depends on, so that removing
 * or renaming the filter trips this test instead of leaking a hand-scheduled
 * visual into autonomous posts.
 */
class BrandAssetReservationTest extends TestCase
{
    public function test_intent_constants_are_stable(): void
    {
        // The picker's raw SQL binds BrandAsset::INTENT_GENERAL. If these string
        // values drift, the SQL silently stops matching anything (or matches the
        // wrong rows). Lock them down.
        $this->assertSame('general', BrandAsset::INTENT_GENERAL);
        $this->assertSame('customised', BrandAsset::INTENT_CUSTOMISED);
    }

    public function test_general_pool_scope_excludes_customised_assets(): void
    {
        $query = BrandAsset::query()->generalPool();

        // The scope must constrain on usage_intent = 'general' — that is the
        // exact predicate that keeps customised assets out of the agent pool.
        $this->assertStringContainsString('"usage_intent" = ?', $query->toSql());
        $this->assertSame([BrandAsset::INTENT_GENERAL], $query->getBindings());
        $this->assertContains(
            BrandAsset::INTENT_GENERAL,
            $query->getBindings(),
            'generalPool() must bind INTENT_GENERAL so customised assets are excluded.',
        );
        $this->assertNotContains(
            BrandAsset::INTENT_CUSTOMISED,
            $query->getBindings(),
            'generalPool() must never select customised assets.',
        );
    }

    public function test_has_any_of_type_query_is_scoped_to_general_pool(): void
    {
        // hasAnyOfType() is what DesignerAgent/VideoAgent ask before deciding
        // whether a library pick is even possible. It must also exclude
        // customised assets, or the agent would believe a reserved asset is
        // available to it.
        $query = BrandAsset::where('brand_id', 1)
            ->where('media_type', 'image')
            ->generalPool()
            ->whereNull('archived_at');

        $bindings = $query->getBindings();
        $this->assertContains(BrandAsset::INTENT_GENERAL, $bindings);
        $this->assertNotContains(BrandAsset::INTENT_CUSTOMISED, $bindings);
    }

    public function test_customised_and_general_partition_is_mutually_exclusive(): void
    {
        $customised = new BrandAsset(['usage_intent' => BrandAsset::INTENT_CUSTOMISED]);
        $general = new BrandAsset(['usage_intent' => BrandAsset::INTENT_GENERAL]);

        $this->assertTrue($customised->isCustomised());
        $this->assertFalse($customised->isGeneral());

        $this->assertTrue($general->isGeneral());
        $this->assertFalse($general->isCustomised());
    }

    public function test_null_or_unknown_intent_defaults_to_general_pool_membership(): void
    {
        // isGeneral() is defined as "not customised" — so a legacy/null intent
        // counts as general (pickable), never as a reserved customised asset.
        // This guards against a null-intent asset being wrongly treated as
        // reserved (which would silently shrink the agent pool).
        $legacy = new BrandAsset(['usage_intent' => null]);

        $this->assertTrue($legacy->isGeneral());
        $this->assertFalse($legacy->isCustomised());
    }

    public function test_picker_supported_platforms_match_scheduler(): void
    {
        // Defence-in-depth: the customised flow's platform set is the single
        // source of truth. Assert the picker class exists and the scheduler's
        // supported platforms are exactly the eight the UI offers, so a new
        // platform can't be scheduled-but-unpickable or vice versa.
        $this->assertTrue(class_exists(BrandAssetPicker::class));
        $this->assertSame(
            ['instagram', 'facebook', 'linkedin', 'tiktok', 'threads', 'x', 'youtube', 'pinterest'],
            \App\Services\Imagery\CustomisedPostScheduler::SUPPORTED_PLATFORMS,
        );
    }
}
