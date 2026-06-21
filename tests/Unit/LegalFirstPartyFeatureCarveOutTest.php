<?php

namespace Tests\Unit;

use App\Agents\Prompts\ComplianceLegalPrompt;
use Tests\TestCase;

/**
 * Regression: the legal compliance judge (ICC Art. 5 substantiation) was
 * over-firing on a brand plainly describing its OWN product's features —
 * "runs six specialised agents", "every caption ships with a source and a
 * score" were failed as "unsubstantiated claims" even though a first-party
 * feature description is a verifiable factual claim the advertiser
 * substantiates by building the product.
 *
 * The fix is two-layered: (1) the GL-AD-002 directive in the seeder is scoped
 * so the SAME over-broad text is no longer injected into the Strategist/Writer
 * at write-time, and (2) the judge prompt (ComplianceLegalPrompt) carries an
 * explicit carve-out clause + a worked PASS example so the model stops
 * over-generalising at the backstop.
 *
 * Both layers are asserted DB-free at the source level — the same idiom as
 * LegalShiftLeftWiringTest — because the live behaviour depends on the exact
 * directive text and prompt wording, not on a database row.
 */
class LegalFirstPartyFeatureCarveOutTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    public function test_prompt_version_bumped_for_the_carve_out(): void
    {
        // The prompt version is tagged on every LLM call; a content change MUST
        // ship with a version bump so a regression is traceable in the logs.
        $this->assertSame('compliance.legal.v1.2', ComplianceLegalPrompt::VERSION);
    }

    public function test_judge_prompt_carves_out_first_party_feature_claims(): void
    {
        $system = ComplianceLegalPrompt::system();

        // The governing hard-rule clause must be present (not just the example),
        // so it applies to every judgment regardless of the worked examples.
        $this->assertStringContainsString('FIRST-PARTY FEATURE CLAIMS ARE NOT VIOLATIONS', $system);
        $this->assertStringContainsString('substantiates by building the product', $system);

        // The carve-out must explicitly NOT restrict a brand describing its own
        // product, while still naming the things Art. 5 actually targets.
        $this->assertStringContainsString('describing its OWN product', $system);
        $this->assertStringContainsString('UNVERIFIABLE superlatives', $system);
    }

    public function test_judge_prompt_includes_a_passing_first_party_example(): void
    {
        $system = ComplianceLegalPrompt::system();

        // A worked example showing the exact failure shape ("runs six
        // specialised agents") now returns verdict pass — the strongest signal
        // to the model that this class of copy is compliant.
        $this->assertStringContainsString('runs six specialised agents', $system);
        $this->assertStringContainsString('"verdict": "pass"', $system);
        $this->assertStringContainsString('first-party descriptions', $system);
    }

    public function test_seeded_gl_ad_002_directive_permits_first_party_features(): void
    {
        $seeder = $this->source('database/seeders/ComplianceLegalRuleSeeder.php');

        // Pin the GL-AD-002 rule_code is still the row we scoped.
        $this->assertStringContainsString("'rule_code' => 'GL-AD-002'", $seeder);

        // The write-time directive (injected into Strategist + Writer) must now
        // carry the first-party permission, so legitimate feature copy is no
        // longer self-censored upstream.
        $this->assertStringContainsString('first-party factual feature claims are permitted', $seeder);

        // And it must still target the things Art. 5 actually restricts, so we
        // didn't neuter the rule — a true superlative / competitor claim / a
        // guaranteed outcome still fails.
        $this->assertStringContainsString('UNVERIFIABLE superlatives', $seeder);
        $this->assertStringContainsString('comparative claims about competitors', $seeder);
        $this->assertStringContainsString('guaranteed outcomes', $seeder);
    }
}
