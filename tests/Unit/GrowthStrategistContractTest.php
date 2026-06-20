<?php

namespace Tests\Unit;

use App\Agents\GrowthStrategistAgent;
use App\Agents\Prompts\GrowthStrategistPrompt;
use Tests\TestCase;

/**
 * P2 fix — cta_styles were unbounded freeform strings (any length, unlimited
 * count) while hook_patterns were strictly enum-filtered; rationale was asked
 * for in the prompt but optional in the schema. This locks the post-filter to
 * bound/cap cta_styles (parity with hook filtering), aligns the rationale
 * contract, adds a worked example, and bumps the version. Pure (no DB).
 */
class GrowthStrategistContractTest extends TestCase
{
    public function test_version_bumped(): void
    {
        $this->assertSame('growth_strategist.v1.1', GrowthStrategistPrompt::VERSION);
    }

    public function test_rationale_contract_agrees_between_prompt_and_schema(): void
    {
        // The prompt explicitly asks the model to produce a rationale (#2); the
        // schema must require it so the two agree.
        $this->assertContains('rationale', GrowthStrategistPrompt::schema()['required']);
    }

    public function test_prompt_has_worked_example(): void
    {
        $this->assertStringContainsString('# Example', GrowthStrategistPrompt::system());
    }

    public function test_filter_drops_overlong_and_caps_cta_styles(): void
    {
        $longCta = str_repeat('book a teardown ', 30); // ~480 chars — junk, must be dropped
        $guidance = [
            'leads' => [
                'hook_patterns' => ['authority_insight', 'not_a_real_hook'],
                'cta_styles' => [
                    'Book a 15-min audit',
                    '   ',                 // blank → dropped
                    $longCta,              // too long → dropped
                    'Save this for later',
                    'Grab the checklist',
                    'DM us "GROW"',
                    'Start your trial',     // 6th — beyond the cap
                ],
            ],
        ];

        $out = GrowthStrategistAgent::filterObjectiveGuidance($guidance);

        // Out-of-enum hook dropped (existing behaviour preserved).
        $this->assertSame(['authority_insight'], $out['leads']['hook_patterns']);

        $ctas = $out['leads']['cta_styles'];
        // Blank + overlong dropped; count capped.
        $this->assertNotContains($longCta, $ctas);
        $this->assertNotContains('   ', $ctas);
        $this->assertLessThanOrEqual(GrowthStrategistAgent::MAX_CTA_STYLES, count($ctas));
        $this->assertContains('Book a 15-min audit', $ctas);
        // Every surviving CTA is within the length bound.
        foreach ($ctas as $c) {
            $this->assertLessThanOrEqual(GrowthStrategistAgent::MAX_CTA_LENGTH, mb_strlen($c));
        }
    }
}
