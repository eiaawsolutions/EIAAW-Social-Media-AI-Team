<?php

namespace Tests\Unit;

use App\Agents\Concerns\RendersWriterContext;
use App\Models\CalendarEntry;
use Tests\TestCase;

/**
 * Locks the cross-platform de-cloning fix (workstream C): one calendar entry
 * targeting multiple platforms must yield DISTINCT, platform-native Writer
 * directives — so sibling platforms no longer receive a byte-identical user
 * message and stop producing near-identical bodies.
 *
 * The trait's rendering methods are `protected`; this harness exposes them so
 * they can be exercised as pure functions (no DB, no LLM).
 */
class WriterPlatformDirectiveTest extends TestCase
{
    /** A minimal object that mixes in the trait and surfaces its protected renderers. */
    private function harness(): object
    {
        return new class
        {
            use RendersWriterContext;

            public function directive(CalendarEntry $entry, string $platform): string
            {
                return $this->renderPlatformDirective($entry, $platform);
            }

            public function mechanics(string $platform): string
            {
                return self::platformMechanics($platform);
            }

            public function creative(CalendarEntry $entry): string
            {
                return $this->renderCreativeIntent($entry);
            }
        };
    }

    /** Build a non-persisted CalendarEntry with the given platforms + creative blob. */
    private function entry(array $platforms, array $creative = []): CalendarEntry
    {
        $e = new CalendarEntry;
        $e->id = 12345;
        $e->topic = 'How our AI books demos';
        $e->angle = 'behind the scenes';
        $e->pillar = 'behind_the_scenes';
        $e->format = 'carousel';
        $e->objective = 'engagement';
        $e->platforms = $platforms;
        $e->research_brief = $creative ? ['creative' => $creative] : null;

        return $e;
    }

    public function test_platform_mechanics_are_distinct_per_platform(): void
    {
        $h = $this->harness();

        $li = $h->mechanics('linkedin');
        $tt = $h->mechanics('tiktok');

        $this->assertNotSame('', $li);
        $this->assertNotSame('', $tt);
        $this->assertNotSame($li, $tt);
        $this->assertStringContainsStringIgnoringCase('professional authority', $li);
        $this->assertStringContainsStringIgnoringCase('first 3 seconds', $tt);
        // Unknown platform → suppressed.
        $this->assertSame('', $h->mechanics('myspace'));
    }

    public function test_multi_platform_entry_yields_distinct_directives(): void
    {
        $h = $this->harness();
        $entry = $this->entry(['linkedin', 'tiktok']);

        $li = $h->directive($entry, 'linkedin');
        $tt = $h->directive($entry, 'tiktok');

        // Both non-empty, and MATERIALLY different (the core de-cloning guarantee).
        $this->assertNotSame('', $li);
        $this->assertNotSame('', $tt);
        $this->assertNotSame($li, $tt);

        // Each names its own platform + carries the "write a DISTINCT take" nudge
        // because the entry is multi-platform.
        $this->assertStringContainsString('Write natively for linkedin', $li);
        $this->assertStringContainsString('Write natively for tiktok', $tt);
        $this->assertStringContainsStringIgnoringCase('distinct', $li);
        $this->assertStringContainsStringIgnoringCase('distinct', $tt);
    }

    public function test_planned_platform_angle_is_injected_for_that_platform_only(): void
    {
        $h = $this->harness();
        $entry = $this->entry(['linkedin', 'tiktok'], [
            'platform_angles' => [
                'linkedin' => 'A senior ops leader on why judgement never gets automated',
                'tiktok' => 'POV: the AI flags it, you still make the call',
            ],
        ]);

        $li = $h->directive($entry, 'linkedin');
        $tt = $h->directive($entry, 'tiktok');

        // The LinkedIn directive carries the LinkedIn angle, not the TikTok one.
        $this->assertStringContainsString('senior ops leader', $li);
        $this->assertStringNotContainsString('POV: the AI flags it', $li);
        // And vice versa.
        $this->assertStringContainsString('POV: the AI flags it', $tt);
        $this->assertStringNotContainsString('senior ops leader', $tt);
    }

    public function test_single_platform_entry_omits_the_sibling_distinct_nudge(): void
    {
        $h = $this->harness();
        $entry = $this->entry(['linkedin']);

        $li = $h->directive($entry, 'linkedin');

        // Still names the platform + mechanics, but no "also ships to other
        // platforms / write a DISTINCT take" line (there are no siblings).
        $this->assertStringContainsString('Write natively for linkedin', $li);
        $this->assertStringNotContainsString('also ships to other platforms', $li);
    }

    public function test_creative_intent_now_includes_positioning_goal(): void
    {
        $h = $this->harness();
        $entry = $this->entry(['linkedin'], [
            'content_angle' => 'contrarian take on AI hiring',
            'target_emotion' => 'trust',
            'positioning_goal' => 'counter_position',
        ]);

        $lines = $h->creative($entry);

        $this->assertStringContainsString('Content angle', $lines);
        $this->assertStringContainsString('Target emotion', $lines);
        $this->assertStringContainsString('Positioning goal', $lines);
        $this->assertStringContainsString('counter_position', $lines);
    }

    /**
     * The Strategist now EMITS platform_angles as an array of {platform, angle}
     * objects (Anthropic rejects an open object map). sanitisePlatformAngles must
     * normalise that array into the platform=>angle map stored in
     * research_brief.creative and read by the Writer.
     */
    public function test_sanitise_platform_angles_accepts_emitted_array_shape(): void
    {
        $agent = app(\App\Agents\StrategistAgent::class);
        $m = new \ReflectionMethod($agent, 'sanitisePlatformAngles');
        $m->setAccessible(true);

        // Emitted array-of-objects, with one platform NOT in the entry (dropped)
        // and one blank angle (dropped).
        $raw = [
            ['platform' => 'linkedin', 'angle' => 'senior ops leader POV'],
            ['platform' => 'tiktok', 'angle' => 'first-3-seconds hook'],
            ['platform' => 'youtube', 'angle' => 'not a target — drop me'],
            ['platform' => 'instagram', 'angle' => '   '],
        ];
        $out = $m->invoke($agent, $raw, ['linkedin', 'tiktok', 'instagram']);

        $this->assertSame([
            'linkedin' => 'senior ops leader POV',
            'tiktok' => 'first-3-seconds hook',
        ], $out);

        // Defensive: a legacy platform=>string map still works.
        $legacy = $m->invoke($agent, ['linkedin' => 'legacy angle'], ['linkedin']);
        $this->assertSame(['linkedin' => 'legacy angle'], $legacy);

        // Empty / non-array → empty.
        $this->assertSame([], $m->invoke($agent, [], ['linkedin']));
        $this->assertSame([], $m->invoke($agent, null, ['linkedin']));
    }
}
