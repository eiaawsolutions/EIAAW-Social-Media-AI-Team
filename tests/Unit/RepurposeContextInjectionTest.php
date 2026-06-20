<?php

namespace Tests\Unit;

use App\Agents\Concerns\RendersWriterContext;
use App\Agents\Prompts\RepurposePrompt;
use App\Agents\RepurposeAgent;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P0 fix — Repurpose must inject the same research-brief / creative-intent /
 * growth-objective context the Writer (v1.4–v1.6) evolved to consume, so a
 * platform derivative is built from the Strategist's deepened angles and the
 * brand's proven hooks rather than the bare one-line topic. DB-free: builds
 * unsaved models and reflects into the (private) buildUserMessage(). Mirrors
 * the suppression-invariant style of GrowthStrategyRenderingTest.
 */
class RepurposeContextInjectionTest extends TestCase
{
    // ── Shared renderer trait (DB-free pieces) ────────────────────────

    private function traitHost(): object
    {
        return new class
        {
            use RendersWriterContext;

            public function research(CalendarEntry $e): string
            {
                return $this->renderResearchBrief($e);
            }

            public function creative(CalendarEntry $e): string
            {
                return $this->renderCreativeIntent($e);
            }
        };
    }

    public function test_trait_renders_research_brief_angles(): void
    {
        $entry = new CalendarEntry;
        $entry->research_brief = [
            'angles' => [
                ['hook' => 'The 3am email nobody answers', 'thesis' => 'Speed wins deals', 'evidence' => 'reply time data', 'tension' => 'slow vs fast', 'audience' => 'founders'],
            ],
        ];

        $out = $this->traitHost()->research($entry);
        $this->assertStringContainsString('Research brief — 5 angles', $out);
        $this->assertStringContainsString('The 3am email nobody answers', $out);
    }

    public function test_trait_research_brief_suppresses_when_absent(): void
    {
        $entry = new CalendarEntry;
        $this->assertSame('', $this->traitHost()->research($entry));
    }

    public function test_trait_renders_creative_intent(): void
    {
        $entry = new CalendarEntry;
        $entry->research_brief = ['creative' => ['target_emotion' => 'urgency', 'content_angle' => 'cost of waiting']];

        $out = $this->traitHost()->creative($entry);
        $this->assertStringContainsString('Target emotion', $out);
        $this->assertStringContainsString('urgency', $out);
        $this->assertStringContainsString('cost of waiting', $out);
    }

    public function test_trait_creative_intent_suppresses_when_absent(): void
    {
        $entry = new CalendarEntry;
        $this->assertSame('', $this->traitHost()->creative($entry));
    }

    // ── RepurposeAgent::buildUserMessage wiring ───────────────────────

    private function buildMessage(Brand $brand, CalendarEntry $entry, Draft $master, string $platform): string
    {
        $agent = new RepurposeAgent(new LlmGateway);
        $m = new ReflectionMethod($agent, 'buildUserMessage');

        return $m->invoke($agent, $brand, 'BRAND STYLE GUIDE', $entry, $master, $platform);
    }

    public function test_repurpose_message_injects_research_and_creative_blocks(): void
    {
        $brand = new Brand(['name' => 'Acme']);
        $entry = new CalendarEntry([
            'topic' => 'Reply speed', 'pillar' => 'educational',
            'format' => 'single_image', 'objective' => 'leads',
        ]);
        $entry->research_brief = [
            'angles' => [['hook' => 'The 3am email nobody answers', 'thesis' => 'x', 'evidence' => 'y', 'tension' => 'z', 'audience' => 'founders']],
            'creative' => ['target_emotion' => 'urgency', 'content_angle' => 'cost of waiting'],
        ];
        $master = new Draft(['body' => 'Master body about reply speed.', 'platform' => 'linkedin']);
        $master->id = 42;

        $msg = $this->buildMessage($brand, $entry, $master, 'instagram');

        // The derivative now carries the Strategist's deepened angle + intent.
        $this->assertStringContainsString('Research brief — 5 angles', $msg);
        $this->assertStringContainsString('The 3am email nobody answers', $msg);
        $this->assertStringContainsString('Target emotion', $msg);
        $this->assertStringContainsString('cost of waiting', $msg);
    }

    public function test_repurpose_message_byte_identical_suppression_when_unenriched(): void
    {
        $brand = new Brand(['name' => 'Acme']);
        $entry = new CalendarEntry([
            'topic' => 'Reply speed', 'pillar' => 'educational',
            'format' => 'single_image', 'objective' => 'leads',
        ]);
        // No research_brief at all — un-enriched brand.
        $master = new Draft(['body' => 'Master body.', 'platform' => 'linkedin']);
        $master->id = 7;

        $msg = $this->buildMessage($brand, $entry, $master, 'instagram');

        $this->assertStringNotContainsString('Research brief — 5 angles', $msg);
        $this->assertStringNotContainsString('Target emotion', $msg);
    }

    // ── Prompt instruction + version ──────────────────────────────────

    public function test_prompt_version_bumped(): void
    {
        $this->assertSame('repurpose.v1.1', RepurposePrompt::VERSION);
    }

    public function test_prompt_instructs_carousel_slides_for_carousel_formats(): void
    {
        $system = RepurposePrompt::system('instagram');
        $this->assertStringContainsStringIgnoringCase('carousel_slides', $system);
    }

    public function test_prompt_instructs_honouring_research_angle_when_supplied(): void
    {
        $system = RepurposePrompt::system('instagram');
        $this->assertStringContainsStringIgnoringCase('research', $system);
    }
}
