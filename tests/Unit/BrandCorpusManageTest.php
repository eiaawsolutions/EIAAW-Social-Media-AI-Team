<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Covers the Brand-corpus management surface added to BrandCorpusSeed:
 *
 *   1. A per-brand SELECTOR so corpus items tie to the right brand. The bug
 *      this fixes: the page had no picker, so resolveBrand() always fell back
 *      to the first brand and every pasted/scraped item piled onto brand #1
 *      ("one corpus instead of one per brand" in the HQ workspace).
 *   2. EDIT (re-embeds when content changes — the vector must track the text or
 *      RAG + dedup reason over a caption that no longer exists).
 *   3. DELETE (removes an item from grounding + the dedup gate).
 *
 * DB-free by convention (see BrandBusinessFactsTest): the unit suite runs
 * against the live DB connection, so wiring is proven by source inspection
 * rather than by writing rows into prod. The Blade compile itself is covered
 * separately by FilamentViewCompilesTest.
 */
class BrandCorpusManageTest extends TestCase
{
    private function pageSource(): string
    {
        return file_get_contents(app_path('Filament/Agency/Pages/BrandCorpusSeed.php'));
    }

    private function viewSource(): string
    {
        return file_get_contents(
            resource_path('views/filament/agency/pages/brand-corpus-seed.blade.php')
        );
    }

    // ---- Brand selector --------------------------------------------------

    public function test_page_exposes_a_brands_list_for_the_selector(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString('public function brands(', $src);
        // Scoped to the workspace and excludes archived brands.
        $this->assertMatchesRegularExpression(
            '/function brands\(.*?whereNull\(\'archived_at\'\)/s',
            $src,
            'brands() must exclude archived brands',
        );
    }

    public function test_mount_pins_selector_to_a_concrete_brand_id(): void
    {
        // Without this the dropdown had no current value and the page silently
        // used the first brand.
        $this->assertMatchesRegularExpression(
            '/function mount\(.*?\$this->brand = \$this->resolveBrand\(\)\?->id;/s',
            $this->pageSource(),
            'mount() must pin $this->brand to the resolved brand id',
        );
    }

    public function test_switching_brand_rescopes_and_cannot_leak_content(): void
    {
        $src = $this->pageSource();

        // The Livewire hook must exist and re-hydrate facts for the new brand.
        $this->assertStringContainsString('public function updatedBrand(', $src);
        $this->assertMatchesRegularExpression(
            '/function updatedBrand\(.*?hydrateBrandFacts\(\)/s',
            $src,
            'updatedBrand() must re-hydrate the brand facts',
        );
        // Clearing the paste box on switch is the guard that half-typed content
        // can never be saved against the newly-selected brand.
        $this->assertMatchesRegularExpression(
            '/function updatedBrand\(.*?\$this->pasteText = \'\';/s',
            $src,
            'updatedBrand() must clear the paste box so content cannot land on the wrong brand',
        );
    }

    public function test_view_binds_a_live_brand_selector(): void
    {
        $view = $this->viewSource();

        $this->assertStringContainsString('wire:model.live="brand"', $view);
        // Only shown when there's more than one brand to choose between.
        $this->assertStringContainsString('$allBrands->count() > 1', $view);
    }

    // ---- Edit (with re-embed) -------------------------------------------

    public function test_page_exposes_edit_lifecycle_methods(): void
    {
        $src = $this->pageSource();

        foreach (['startEdit', 'cancelEdit', 'saveEdit'] as $method) {
            $this->assertStringContainsString("public function {$method}(", $src, "missing {$method}()");
        }
    }

    public function test_edit_reembeds_only_when_content_changes(): void
    {
        $src = $this->pageSource();

        // The content-changed guard drives whether we pay for a re-embed.
        $this->assertStringContainsString(
            '$contentChanged = $newContent !== (string) $item->content;',
            $src,
        );
        // When it changed we call the embedding service and assign the vector.
        $this->assertMatchesRegularExpression(
            '/if \(\$contentChanged\).*?EmbeddingService::class\)->embed\(.*?\$item->embedding = \$vector;/s',
            $src,
            'saveEdit must re-embed and assign the new vector when content changes',
        );
    }

    public function test_edit_reembed_failure_changes_nothing(): void
    {
        // A Voyage outage must not leave a row whose text and vector disagree.
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable \$e\).*?edit re-embed failed.*?return;/s',
            $this->pageSource(),
            'saveEdit must abort (no save) when re-embedding fails',
        );
    }

    // ---- Delete ----------------------------------------------------------

    public function test_page_exposes_delete_and_view_confirms_it(): void
    {
        $this->assertStringContainsString('public function deleteItem(', $this->pageSource());

        $view = $this->viewSource();
        $this->assertStringContainsString('wire:click="deleteItem(', $view);
        $this->assertStringContainsString('wire:confirm=', $view);
        $this->assertStringContainsString('wire:click="startEdit(', $view);
        $this->assertStringContainsString('wire:click="saveEdit"', $view);
    }

    // ---- IDOR safety -----------------------------------------------------

    public function test_edit_and_delete_are_scoped_to_the_operators_workspace(): void
    {
        $src = $this->pageSource();

        // The single ownership gate every mutating action routes through.
        $this->assertStringContainsString('private function findOwnedItem(', $src);
        $this->assertMatchesRegularExpression(
            '/function findOwnedItem\(.*?whereHas\(\'brand\'.*?where\(\'workspace_id\', \$ws->id\)/s',
            $src,
            'findOwnedItem must constrain the item to a brand in the operator workspace (IDOR guard)',
        );

        // Both mutators resolve the item through the guard, never a raw find().
        foreach (['startEdit', 'deleteItem'] as $method) {
            $this->assertMatchesRegularExpression(
                "/function {$method}\(.*?findOwnedItem\(/s",
                $src,
                "{$method}() must load the item via findOwnedItem()",
            );
        }
    }

    // ---- Display hygiene -------------------------------------------------

    public function test_corpus_items_query_never_selects_the_embedding_column(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString('public function corpusItems(', $src);
        // Pulling 1024-float vectors into the view is wasteful; the explicit
        // column list must omit `embedding`.
        $this->assertMatchesRegularExpression(
            '/function corpusItems\(.*?->get\(\[[^\]]*\]\)/s',
            $src,
            'corpusItems() must select an explicit column list',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function corpusItems\(.*?->get\(\[[^\]]*\'embedding\'[^\]]*\]\)/s',
            $src,
            'corpusItems() must NOT select the embedding column',
        );
    }
}
