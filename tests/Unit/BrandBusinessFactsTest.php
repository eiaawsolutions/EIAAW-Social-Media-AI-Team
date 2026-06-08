<?php

namespace Tests\Unit;

use App\Models\Brand;
use Tests\TestCase;

/**
 * Covers the operator-supplied business-facts enrichment: business_locations
 * and audience_profile on the brands row, rendered into a prompt block the
 * Writer + Strategist inject ABOVE the AI-synthesised brand-style.md so the
 * facts are authoritative ground truth that survives every voice re-synthesis.
 *
 * Two test styles, matching the suite's conventions (see
 * BrandCreateAtomicityTest): the renderer is PURE + static, so it's tested by
 * direct call with no DB; the agent/page WIRING is proven by source inspection
 * because the unit suite runs against the live DB connection (sqlite is
 * commented out in phpunit.xml) and a row-writing test would pollute prod.
 */
class BrandBusinessFactsTest extends TestCase
{
    // ---- Pure renderer (no DB) -------------------------------------------

    public function test_empty_facts_render_nothing(): void
    {
        // Critical invariant: an un-enriched brand must produce a byte-identical
        // prompt to the pre-feature behaviour — no empty headers, no "(none)".
        $this->assertSame('', Brand::renderBrandFactsBlock([], []));
        $this->assertSame('', Brand::renderBrandFactsBlock([['area' => '', 'country' => '']], ['description' => '']));
    }

    public function test_locations_render_with_primary_and_notes(): void
    {
        $block = Brand::renderBrandFactsBlock([
            ['area' => 'Kuala Lumpur', 'country' => 'Malaysia', 'is_primary' => true, 'notes' => 'HQ + flagship'],
            ['area' => 'Penang', 'country' => 'Malaysia', 'is_primary' => false, 'notes' => ''],
        ], []);

        $this->assertStringContainsString('## Business locations', $block);
        $this->assertStringContainsString('- Kuala Lumpur, Malaysia (primary) — HQ + flagship', $block);
        $this->assertStringContainsString('- Penang, Malaysia', $block);
        $this->assertStringNotContainsString('Penang, Malaysia (primary)', $block);
    }

    public function test_audience_renders_description_segments_geo(): void
    {
        $block = Brand::renderBrandFactsBlock([], [
            'description' => 'Time-poor urban professionals 25-40.',
            'segments' => ['Young professionals', 'Remote workers'],
            'geo_focus' => 'Klang Valley',
        ]);

        $this->assertStringContainsString('## Target audience', $block);
        $this->assertStringContainsString('Time-poor urban professionals 25-40.', $block);
        $this->assertStringContainsString('Segments: Young professionals, Remote workers', $block);
        $this->assertStringContainsString('Geographic focus: Klang Valley', $block);
    }

    public function test_block_is_marked_authoritative(): void
    {
        // The agents inject this above brand-style.md; the header must instruct
        // the model to honour operator facts over inferred voice.
        $block = Brand::renderBrandFactsBlock([
            ['area' => 'Singapore', 'country' => 'Singapore'],
        ], []);

        $this->assertStringContainsString('operator-supplied', $block);
        $this->assertStringContainsString('authoritative', $block);
    }

    public function test_blank_location_rows_are_skipped(): void
    {
        $block = Brand::renderBrandFactsBlock([
            ['area' => '', 'country' => '', 'is_primary' => true, 'notes' => 'ignored'],
            ['area' => 'Johor Bahru', 'country' => 'Malaysia'],
        ], []);

        $this->assertStringNotContainsString('ignored', $block);
        $this->assertStringContainsString('- Johor Bahru, Malaysia', $block);
    }

    // ---- Model wiring ----------------------------------------------------

    public function test_brand_casts_and_fillable_include_business_facts(): void
    {
        $src = file_get_contents(app_path('Models/Brand.php'));

        foreach (['business_locations', 'audience_profile'] as $col) {
            $this->assertStringContainsString("'{$col}',", $src, "Brand \$fillable missing {$col}");
            $this->assertMatchesRegularExpression(
                "/'{$col}'\s*=>\s*'array'/",
                $src,
                "Brand casts() missing {$col} => array",
            );
        }
    }

    // ---- Agent injection (source inspection) -----------------------------

    public function test_writer_agent_injects_brand_facts_above_brand_style(): void
    {
        $src = file_get_contents(app_path('Agents/WriterAgent.php'));

        $this->assertStringContainsString('brandFactsBlock', $src, 'WriterAgent must read the facts block');
        // Both the greenfield and redraft builders interpolate the facts block
        // immediately before the brand-style.md header.
        $this->assertMatchesRegularExpression(
            '/\{\$factsBlock\}# brand-style\.md/',
            $src,
            'WriterAgent must place the facts block directly above brand-style.md',
        );
        // Two call sites: buildUserMessage + buildRedraftMessage.
        $this->assertSame(
            2,
            substr_count($src, '$factsBlock = $this->renderBrandFacts($brand);'),
            'Both Writer message builders must render the facts block',
        );
    }

    public function test_strategist_agent_injects_brand_facts_above_brand_style(): void
    {
        $src = file_get_contents(app_path('Agents/StrategistAgent.php'));

        $this->assertStringContainsString('brandFactsBlock', $src, 'StrategistAgent must read the facts block');
        $this->assertMatchesRegularExpression(
            '/\{\$factsSection\}\s*\n# brand-style\.md/',
            $src,
            'StrategistAgent must place the facts section directly above brand-style.md',
        );
    }

    // ---- Persistence wiring (source inspection) --------------------------

    public function test_corpus_page_persists_facts_to_the_brand(): void
    {
        $src = file_get_contents(app_path('Filament/Agency/Pages/BrandCorpusSeed.php'));

        $this->assertStringContainsString('public function saveBrandFacts(', $src);
        $this->assertStringContainsString("'business_locations' =>", $src);
        $this->assertStringContainsString("'audience_profile' =>", $src);
        // Saving must invalidate readiness so the wizard reflects the new state.
        $this->assertMatchesRegularExpression(
            '/function saveBrandFacts\(.*?SetupReadiness::class\)->invalidate/s',
            $src,
            'saveBrandFacts must invalidate setup readiness',
        );
    }

    public function test_corpus_view_exposes_business_facts_card(): void
    {
        $view = file_get_contents(
            resource_path('views/filament/agency/pages/brand-corpus-seed.blade.php')
        );

        $this->assertStringContainsString('Tell us about your business', $view);
        $this->assertStringContainsString('wire:click="saveBrandFacts"', $view);
        $this->assertStringContainsString('wire:click="addLocation"', $view);
        $this->assertStringContainsString('wire:model="audienceDescription"', $view);
    }
}
