<?php

namespace Tests\Unit;

use App\Agents\Prompts\ResearcherPrompt;
use Tests\TestCase;

/**
 * P2 fix — the prompt demanded "EXACTLY 5 angles" but the schema only set
 * minItems:1 (the Anthropic validator rejects bounded maxItems on some models),
 * and the agent slices to 5 after the fact. Made the post-validation contract
 * explicit in the prompt — angles 6+ are discarded, and fewer than the target
 * degrades gracefully (research_brief stays null, Writer falls back). DB-free.
 */
class ResearcherPromptBoundTest extends TestCase
{
    public function test_version_bumped(): void
    {
        $this->assertSame('researcher.v1.1', ResearcherPrompt::VERSION);
    }

    public function test_prompt_documents_the_post_slice_contract(): void
    {
        $system = ResearcherPrompt::system();
        // The model should know surplus angles are dropped (so it doesn't waste
        // tokens) and that a short, well-grounded set is acceptable.
        $this->assertStringContainsStringIgnoringCase('discard', $system);
    }

    public function test_schema_keeps_minitems_one_no_bounded_maxitems(): void
    {
        // The Anthropic validator rejects bounded maxItems on some models — the
        // bound stays in the prompt + the agent's array_slice. Lock that we did
        // NOT add a maxItems that could break structured output in prod.
        $angles = ResearcherPrompt::schema()['properties']['angles'];
        $this->assertSame(1, $angles['minItems']);
        $this->assertArrayNotHasKey('maxItems', $angles);
    }
}
