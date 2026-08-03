<?php

namespace Tests\Unit;

use App\Services\Brands\BrandScope;
use Tests\TestCase;

/**
 * The global brand scope — the multi-select brand picker in the Agency topbar
 * that every page and table honours.
 *
 * The reconcile()/effectiveIds() pair is pure (no session, no DB), so the parts
 * that carry the isolation invariant are tested directly here. The wiring into
 * pages and resources is asserted by source inspection in
 * BrandScopeWiringTest, following the DB-free convention of this suite.
 */
class BrandScopeTest extends TestCase
{
    // ---- reconcile(): the isolation invariant ----------------------------

    public function test_ids_outside_the_workspace_are_dropped(): void
    {
        // 99 belongs to another tenant. Session data is attacker-influenceable,
        // so it must never survive into a query.
        $this->assertSame(
            [7],
            BrandScope::reconcile([7, 99], [7, 8, 12]),
        );
    }

    public function test_a_wholly_foreign_selection_collapses_to_all_not_to_the_foreign_ids(): void
    {
        // Worst case: every stored id is foreign. The result must be "no
        // explicit subset" (which effectiveIds() expands to the operator's own
        // brands), never the foreign ids themselves.
        $this->assertSame([], BrandScope::reconcile([99, 100], [7, 8]));
    }

    public function test_non_numeric_and_duplicate_ids_are_discarded(): void
    {
        $this->assertSame(
            [7, 8],
            BrandScope::reconcile([7, '8', 7, 'abc', null, 8], [7, 8, 12]),
        );
    }

    public function test_selecting_every_available_brand_collapses_to_all(): void
    {
        // Empty-means-all, so a brand added tomorrow is picked up automatically
        // instead of being invisible behind a stale explicit list.
        $this->assertSame([], BrandScope::reconcile([7, 8, 12], [7, 8, 12]));
    }

    public function test_a_partial_selection_is_preserved_and_sorted(): void
    {
        $this->assertSame([7, 12], BrandScope::reconcile([12, 7], [7, 8, 12]));
    }

    public function test_reconcile_against_no_available_brands_yields_nothing(): void
    {
        $this->assertSame([], BrandScope::reconcile([7, 8], []));
    }

    // ---- effectiveIds(): what actually reaches the query -----------------

    public function test_all_expands_to_every_available_brand(): void
    {
        // Expanding rather than returning "no filter" is what lets callers
        // always whereIn() and get workspace isolation for free.
        $this->assertSame([7, 8, 12], BrandScope::effectiveIds([], [7, 8, 12]));
    }

    public function test_an_explicit_subset_is_used_verbatim(): void
    {
        $this->assertSame([8], BrandScope::effectiveIds([8], [7, 8, 12]));
    }

    public function test_a_workspace_with_no_brands_produces_an_empty_filter(): void
    {
        // whereIn('brand_id', []) matches nothing — correct: no brands, no data.
        $this->assertSame([], BrandScope::effectiveIds([], []));
    }

    /**
     * The composed guarantee the rest of the app relies on: whatever is in the
     * session, the ids handed to a query are always a subset of the operator's
     * own workspace. This is the single property that makes it safe to pass
     * brandIds() straight into whereIn().
     */
    public function test_composed_result_can_never_escape_the_workspace(): void
    {
        $available = [7, 8, 12];

        foreach ([[99], [7, 99], [], ['x'], [99, 100], [7, 8, 12, 99]] as $tampered) {
            $effective = BrandScope::effectiveIds(
                BrandScope::reconcile($tampered, $available),
                $available,
            );

            $this->assertEmpty(
                array_diff($effective, $available),
                'brandIds() leaked an id outside the workspace for input: '.json_encode($tampered),
            );
        }
    }
}
