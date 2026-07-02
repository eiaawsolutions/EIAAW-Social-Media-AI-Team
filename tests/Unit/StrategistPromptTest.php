<?php

namespace Tests\Unit;

use App\Agents\Prompts\StrategistPrompt;
use Tests\TestCase;

class StrategistPromptTest extends TestCase
{
    public function test_version_bumped_for_strategy_briefing(): void
    {
        // v1.1 added Competitor signals; v1.2 the creative-director enrichment;
        // v1.5 the Strategy Briefing (competitor-strategy synthesis + market &
        // trend brief); v1.6 the Growth strategy block; v1.7 the anti-recycling
        // "Recently published" exclusion + the goal-lagging pivot; v1.8 reworded
        // the unrecoverable scheduled_time instruction; v1.9 the director +
        // brand-marketer + platform-mechanics upgrade (positioning_goal +
        // platform_angles + conceptual DO-NOT-REPEAT). The bump must be visible
        // so the optimizer treats prior calendars as a different prompt-version
        // input cohort.
        $this->assertSame('strategist.v1.9', StrategistPrompt::VERSION);
    }

    public function test_system_prompt_includes_recently_published_and_lagging_goal_directives(): void
    {
        $prompt = StrategistPrompt::system();

        // Anti-recycling: the hard exclusion of already-shipped topics/angles.
        $this->assertStringContainsString('Recently published', $prompt);
        $this->assertStringContainsString('DO NOT REPEAT', $prompt);
        // Reusing a pillar stays allowed; reusing a topic/angle is the target.
        $this->assertStringContainsStringIgnoringCase('reusing a content pillar is expected', $prompt);

        // Goal-lagging pivot: skew the month toward a lagging goal's metric.
        $this->assertStringContainsString('Goals behind pace', $prompt);
        $this->assertStringContainsStringIgnoringCase('lagging', $prompt);
    }

    public function test_system_prompt_includes_competitor_strategy_and_market_trend_sections(): void
    {
        $prompt = StrategistPrompt::system();

        // Dim 2 — competitor strategy synthesis + whitespace targeting.
        $this->assertStringContainsString('Competitor strategy synthesis', $prompt);
        $this->assertStringContainsStringIgnoringCase('whitespace', $prompt);

        // Dim 1+3 — market & trend brief + the no-fabrication guard.
        $this->assertStringContainsString('Market & Trend brief', $prompt);
        $this->assertStringContainsString('NEVER assert a market statistic', $prompt);
    }

    public function test_system_prompt_includes_hook_framework_and_creative_fields(): void
    {
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('Hook framework', $prompt);
        $this->assertStringContainsString('target_emotion', $prompt);
        $this->assertStringContainsString('content_angle', $prompt);
        // Still emits only the publish-safe format enum, never invented strings.
        $this->assertStringContainsString('Do NOT invent new format strings', $prompt);
    }

    public function test_schema_exposes_target_emotion_and_content_angle(): void
    {
        $props = StrategistPrompt::schema()['properties']['entries']['items']['properties'];

        $this->assertArrayHasKey('target_emotion', $props);
        $this->assertArrayHasKey('content_angle', $props);
        // They must stay OPTIONAL — older parsing + the agent's persistence
        // both tolerate their absence.
        $required = StrategistPrompt::schema()['properties']['entries']['items']['required'];
        $this->assertNotContains('target_emotion', $required);
        $this->assertNotContains('content_angle', $required);
    }

    public function test_system_prompt_includes_competitor_awareness_section(): void
    {
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('Competitor awareness', $prompt);
        $this->assertStringContainsStringIgnoringCase('counter-positioning', $prompt);
        $this->assertStringContainsString('NEVER claim competitor metrics', $prompt);
    }

    public function test_system_prompt_still_demands_30_entries(): void
    {
        // Regression: don't lose the original calendar planning rules
        // when adding competitor awareness.
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('30 entries', $prompt);
        $this->assertStringContainsString('Output ONLY the JSON document', $prompt);
    }

    public function test_system_prompt_has_director_persona_and_positioning_discipline(): void
    {
        // v1.9: the persona is a social media DIRECTOR + brand strategist, and
        // every entry ladders to a positioning job — not just "on-brand" filler.
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsStringIgnoringCase('director', $prompt);
        $this->assertStringContainsString('Brand positioning', $prompt);
        $this->assertStringContainsString('positioning_goal', $prompt);
        // Names the mono-theme trap explicitly: same message reworded = recycling.
        $this->assertStringContainsStringIgnoringCase('in fresh wording is still recycling', $prompt);
    }

    public function test_system_prompt_has_platform_mechanics_and_per_platform_angles(): void
    {
        // v1.9: per-platform mechanics + the rule that one entry on multiple
        // platforms gets a DISTINCT native angle each (the strategy-side half
        // of the cross-platform de-cloning fix).
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsString('Platform mechanics', $prompt);
        $this->assertStringContainsString('platform_angles', $prompt);
        // A couple of the platform-specific mechanics cues.
        $this->assertStringContainsStringIgnoringCase('first 3 seconds', $prompt); // tiktok
        $this->assertStringContainsStringIgnoringCase('watch-time', $prompt);      // youtube
    }

    public function test_do_not_repeat_rule_is_conceptual_not_just_string(): void
    {
        // v1.9: recycling is judged at the IDEA level, not just the topic string.
        $prompt = StrategistPrompt::system();

        $this->assertStringContainsStringIgnoringCase('distinct topic string is NOT enough', $prompt);
    }

    public function test_schema_exposes_positioning_goal_and_platform_angles_optional(): void
    {
        $items = StrategistPrompt::schema()['properties']['entries']['items'];
        $props = $items['properties'];

        $this->assertArrayHasKey('positioning_goal', $props);
        $this->assertArrayHasKey('platform_angles', $props);

        // positioning_goal is a constrained enum of strategic jobs.
        $this->assertContains('whitespace', $props['positioning_goal']['enum']);
        $this->assertContains('counter_position', $props['positioning_goal']['enum']);

        // platform_angles is an ARRAY of {platform, angle} objects — NOT an open
        // object map. Anthropic's structured-output validator rejects
        // `additionalProperties: object`, so a free-form map is not emittable.
        $this->assertSame('array', $props['platform_angles']['type']);
        $item = $props['platform_angles']['items'];
        $this->assertSame('object', $item['type']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertEqualsCanonicalizing(['platform', 'angle'], $item['required']);

        // Both stay OPTIONAL so an un-enriched call and the agent's persistence
        // tolerate their absence (behavioural compatibility with pre-v1.9).
        $this->assertNotContains('positioning_goal', $items['required']);
        $this->assertNotContains('platform_angles', $items['required']);
    }

    public function test_schema_never_uses_object_valued_additional_properties(): void
    {
        // Regression guard for the prod 400: Anthropic rejects
        // `additionalProperties: <object>` — it must be `false` (or absent)
        // everywhere in the emitted schema. Walk the whole tree.
        $walk = function ($node) use (&$walk): void {
            if (! is_array($node)) {
                return;
            }
            if (array_key_exists('additionalProperties', $node)) {
                $this->assertFalse(
                    is_array($node['additionalProperties']),
                    'additionalProperties must be false, never an object schema (Anthropic rejects it).'
                );
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk(StrategistPrompt::schema());
    }
}
