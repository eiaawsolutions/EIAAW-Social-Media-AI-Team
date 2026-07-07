<?php

namespace Tests\Unit;

use App\Agents\DesignerAgent;
use App\Models\Brand;
use App\Models\BrandStyle;
use App\Models\Draft;
use App\Services\Imagery\ImageCreativeDirection;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the Phase-1 brand-visual-card injection in DesignerAgent::buildPrompt():
 *   - an enriched client brand gets its BrandVisualCard directive + facts in the
 *     photo prompt;
 *   - an un-enriched client brand falls back to the exact legacy prompt (no card,
 *     no regression);
 *   - the NO-TEXT clause and realism block survive on every path (invariant);
 *   - operator business facts are injected when present.
 * DB-free: unsaved Draft/Brand/BrandStyle with the currentStyle relation set by
 * hand and reflection into the private method (pattern: DesignerHookSlideBoundTest).
 */
class DesignerBrandVisualCardPromptTest extends TestCase
{
    private function buildPrompt(Brand $brand, Draft $draft): string
    {
        $m = new ReflectionMethod(DesignerAgent::class, 'buildPrompt');

        return $m->invoke(new DesignerAgent(new LlmGateway), $brand, $draft);
    }

    private function brand(array $brandAttrs, ?array $styleAttrs): Brand
    {
        $brand = new Brand;
        $brand->forceFill($brandAttrs);

        if ($styleAttrs !== null) {
            $style = new BrandStyle;
            $style->forceFill($styleAttrs);
            $brand->setRelation('currentStyle', $style);
        } else {
            $brand->setRelation('currentStyle', null);
        }

        // No workspace → EiaawBrandLock::appliesTo() is false → client path.
        $brand->setRelation('workspace', null);

        return $brand;
    }

    /** A plain single-image photo draft (not poster/infographic/hook routed). */
    private function photoDraft(): Draft
    {
        $draft = new Draft;
        $draft->forceFill([
            'platform' => 'instagram',
            'body' => 'A short lifestyle caption about the brand moment.',
            'content_type' => 'image',
        ]);

        return $draft;
    }

    public function test_enriched_client_brand_gets_card_and_facts_in_prompt(): void
    {
        $brand = $this->brand(
            [
                'name' => 'Acme Cafe',
                'industry' => 'food',
                'company_profile' => 'A neighbourhood specialty coffee bar in Kuching.',
            ],
            [
                'visual_identity' => [
                    'palette' => [['hex' => '6F4E37', 'name' => 'Coffee brown', 'role' => 'accent']],
                    'mood_adjectives' => ['warm', 'cosy'],
                    'imagery_style' => 'real cups, hands and latte art on wood',
                    'forbidden_visuals' => ['neon glow'],
                ],
            ],
        );

        $prompt = $this->buildPrompt($brand, $this->photoDraft());

        // Brand card clauses present.
        $this->assertStringContainsStringIgnoringCase('Acme Cafe house style', $prompt);
        $this->assertStringContainsStringIgnoringCase('#6F4E37', $prompt);
        $this->assertStringContainsStringIgnoringCase('latte art', $prompt);
        $this->assertStringContainsStringIgnoringCase('warm and cosy', $prompt);

        // Operator business facts injected.
        $this->assertStringContainsStringIgnoringCase('Brand context', $prompt);
        $this->assertStringContainsStringIgnoringCase('specialty coffee bar', $prompt);

        // Invariants: no-text clause + realism block + tension clause survive.
        $this->assertStringContainsString('ABSOLUTELY NO TEXT', $prompt);
        $this->assertStringContainsStringIgnoringCase('depth of field', $prompt);
        $this->assertStringContainsStringIgnoringCase('scroll-stopping', $prompt);
    }

    public function test_unenriched_client_brand_falls_back_to_legacy_prompt(): void
    {
        // No style at all → card unavailable → legacy generic prompt.
        $brand = $this->brand(['name' => 'Bare Brand', 'industry' => ''], null);

        $prompt = $this->buildPrompt($brand, $this->photoDraft());

        // Legacy shape: named brand + generic per-platform aesthetic, NO card.
        $this->assertStringContainsString('for the brand "Bare Brand"', $prompt);
        $this->assertStringNotContainsString('house style', $prompt);
        // Facts clause absent when operator set none.
        $this->assertStringNotContainsString('Brand context', $prompt);

        // Invariants still hold.
        $this->assertStringContainsString('ABSOLUTELY NO TEXT', $prompt);
        $this->assertStringContainsStringIgnoringCase('depth of field', $prompt);
    }

    public function test_unenriched_brand_still_gets_facts_when_operator_set_them(): void
    {
        $brand = $this->brand(
            ['name' => 'Bare Brand', 'industry' => '', 'company_profile' => 'We sell handmade ceramics.'],
            null,
        );

        $prompt = $this->buildPrompt($brand, $this->photoDraft());

        $this->assertStringContainsString('for the brand "Bare Brand"', $prompt); // still legacy shape
        $this->assertStringContainsStringIgnoringCase('handmade ceramics', $prompt); // facts injected
    }

    public function test_hook_poster_gate_fires_for_strong_headline_single_image(): void
    {
        $this->assertTrue(ImageCreativeDirection::shouldRouteToHookPoster(
            'single_image',
            'promotional',
            'a lifestyle shot',
            ['headline' => 'Three mistakes killing your morning routine'],
        ));

        // Too-short headline → stays a photo.
        $this->assertFalse(ImageCreativeDirection::shouldRouteToHookPoster(
            'single_image',
            'promotional',
            'a lifestyle shot',
            ['headline' => 'Hi'],
        ));

        // Not single_image → not a hook poster.
        $this->assertFalse(ImageCreativeDirection::shouldRouteToHookPoster(
            'reel',
            'promotional',
            null,
            ['headline' => 'Three mistakes killing your morning routine'],
        ));
    }
}
