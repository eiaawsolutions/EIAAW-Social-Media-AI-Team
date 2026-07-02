<?php

namespace Tests\Unit;

use App\Agents\Prompts\WriterPrompt;
use App\Models\Brand;
use App\Models\Workspace;
use Tests\TestCase;

/**
 * Locks the v1.7 anti-fabrication guardrails into the Writer's system prompt.
 *
 * The universal rules must be present for EVERY brand (HQ and every signup
 * client); the HQ add-on must appear only when the brand's workspace is
 * eiaaw_internal, and must NOT leak onto client-brand prompts.
 *
 * These are in-memory model instances with a hand-set workspace relation — no
 * DB — so the test asserts pure prompt content (same idiom as StrategistPromptTest).
 */
class WriterPromptTest extends TestCase
{
    public function test_version_bumped_for_anti_fabrication(): void
    {
        // Bumping the version makes prior compliance_failed/pending drafts
        // eligible for redraft under the hardened prompt.
        $this->assertSame('writer.v1.7', WriterPrompt::VERSION);
    }

    public function test_universal_hard_rules_forbid_implied_traction_for_every_brand(): void
    {
        // No brand argument → the base prompt every brand shares.
        $prompt = WriterPrompt::system('linkedin');

        // The pre-existing narrow rule stays.
        $this->assertStringContainsString('Never invent statistics, awards, customer names, or quotes.', $prompt);

        // The broadened rule: implied traction the Writer can't cite.
        $this->assertStringContainsString('Never imply traction you cannot cite', $prompt);
        $this->assertStringContainsStringIgnoringCase('invented deployments', $prompt);
        $this->assertStringContainsStringIgnoringCase('cadence', $prompt);

        // Hypotheticals must read as hypotheticals.
        $this->assertStringContainsString('A hypothetical is allowed only when it reads as one', $prompt);
    }

    public function test_hq_brand_gets_extra_truth_constraints(): void
    {
        $prompt = WriterPrompt::system('linkedin', null, $this->brandOnPlan('eiaaw_internal'));

        // Universal rules still present.
        $this->assertStringContainsString('Never imply traction you cannot cite', $prompt);

        // HQ add-on present.
        $this->assertStringContainsString('You are writing about EIAAW itself', $prompt);
        $this->assertStringContainsStringIgnoringCase('designed to do', $prompt);
        $this->assertStringContainsStringIgnoringCase('no published client deployments', $prompt);
    }

    public function test_client_brand_does_not_get_hq_addon_but_keeps_universal_rules(): void
    {
        $prompt = WriterPrompt::system('linkedin', null, $this->brandOnPlan('agency'));

        // Client brands still get the universal anti-fabrication rules...
        $this->assertStringContainsString('Never imply traction you cannot cite', $prompt);

        // ...but NOT the EIAAW-about-itself add-on.
        $this->assertStringNotContainsString('You are writing about EIAAW itself', $prompt);
    }

    public function test_null_brand_has_no_hq_addon(): void
    {
        $prompt = WriterPrompt::system('linkedin');
        $this->assertStringNotContainsString('You are writing about EIAAW itself', $prompt);
    }

    /**
     * An in-memory Brand whose workspace relation is pre-set to a given plan,
     * so WriterPrompt::system()'s optional($brand->workspace)->plan branch
     * resolves without a database round-trip.
     */
    private function brandOnPlan(string $plan): Brand
    {
        $workspace = new Workspace(['plan' => $plan]);
        $brand = new Brand(['name' => 'Test Brand']);
        $brand->setRelation('workspace', $workspace);

        return $brand;
    }
}
