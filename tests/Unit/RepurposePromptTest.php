<?php

namespace Tests\Unit;

use App\Agents\Prompts\RepurposePrompt;
use App\Agents\Prompts\WriterPrompt;
use Tests\TestCase;

class RepurposePromptTest extends TestCase
{
    public function test_version_is_locked(): void
    {
        $this->assertSame('repurpose.v1.0', RepurposePrompt::VERSION);
    }

    public function test_system_prompt_forbids_verbatim_copy(): void
    {
        $prompt = RepurposePrompt::system('linkedin');

        $this->assertStringContainsString('Do NOT copy the master verbatim', $prompt);
        $this->assertStringContainsString('Identical text across platforms is a duplication failure', $prompt);
    }

    public function test_system_prompt_includes_target_platform_label(): void
    {
        $li = RepurposePrompt::system('linkedin');
        $tt = RepurposePrompt::system('tiktok');

        $this->assertStringContainsString('Linkedin', $li);
        $this->assertStringContainsString('Tiktok', $tt);
    }

    public function test_system_prompt_includes_per_platform_char_limit(): void
    {
        $x = RepurposePrompt::system('x');
        $li = RepurposePrompt::system('linkedin');

        $this->assertStringContainsString('280', $x);   // X cap
        $this->assertStringContainsString('3000', $li); // LinkedIn cap
    }

    public function test_schema_matches_writer_schema_exactly(): void
    {
        // Derivatives use the exact same shape so Compliance/Designer/Video
        // don't need to branch on draft origin. If these ever diverge, the
        // check_type detection in ComplianceAgent breaks silently — fail
        // loud here instead.
        $repurpose = RepurposePrompt::schema('linkedin');
        $writer = WriterPrompt::schema('linkedin');

        $this->assertSame($writer, $repurpose);
    }

    public function test_branded_artefacts_required_on_derivatives(): void
    {
        $prompt = RepurposePrompt::system('instagram');

        $this->assertStringContainsString('Branded artefacts', $prompt);
        $this->assertStringContainsString('quote', $prompt);
        $this->assertStringContainsString('voiceover', $prompt);
    }
}
