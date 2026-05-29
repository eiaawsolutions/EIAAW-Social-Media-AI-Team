<?php

namespace Tests\Unit;

use App\Services\Support\ChatbotPrompts;
use Tests\TestCase;

/**
 * Locks the floating chatbot's surface-aware behaviour and — critically — the
 * guardrail spine that satisfies the requirement: "shouldn't reveal anything
 * outside SMT guardrails when operating within SMT, apart from EIAAW general
 * knowledge and products." Every surface must carry SCOPE LOCK + NO INTERNALS +
 * NO PROMPT-INJECTION; the landing prompt must be sale-conversion; the panel
 * prompts must be guide-steps; and a spoofed/unknown surface must clamp to the
 * most-restrictive (landing) prompt.
 */
class ChatbotPromptsTest extends TestCase
{
    public function test_unknown_or_empty_surface_clamps_to_landing(): void
    {
        $this->assertSame('landing', ChatbotPrompts::normaliseSurface(null));
        $this->assertSame('landing', ChatbotPrompts::normaliseSurface(''));
        $this->assertSame('landing', ChatbotPrompts::normaliseSurface('totally-made-up'));
        $this->assertSame('landing', ChatbotPrompts::normaliseSurface('LANDING'));

        // Known surfaces pass through (case-insensitive).
        $this->assertSame('client', ChatbotPrompts::normaliseSurface('client'));
        $this->assertSame('hq', ChatbotPrompts::normaliseSurface('HQ'));
    }

    public function test_for_unknown_surface_returns_landing_prompt_not_a_guide(): void
    {
        // Defence-in-depth: even if a caller passes garbage to ::for(), they get
        // the public sale-conversion prompt, never the more-revealing guide.
        $prompt = ChatbotPrompts::for('garbage');
        $this->assertStringContainsString('SALE CONVERSION', $prompt);
    }

    public function test_landing_prompt_is_sale_conversion(): void
    {
        $p = ChatbotPrompts::for(ChatbotPrompts::SURFACE_LANDING);
        $this->assertStringContainsString('SALE CONVERSION', $p);
        $this->assertStringContainsStringIgnoringCase('subscribe', $p);
    }

    public function test_client_and_hq_prompts_are_guide_steps(): void
    {
        $client = ChatbotPrompts::for(ChatbotPrompts::SURFACE_CLIENT);
        $hq = ChatbotPrompts::for(ChatbotPrompts::SURFACE_HQ);

        $this->assertStringContainsString('GUIDE STEPS', $client);
        $this->assertStringContainsString('GUIDE STEPS', $hq);
        // Panels are NOT a sales pitch.
        $this->assertStringNotContainsString('SALE CONVERSION', $client);
        $this->assertStringNotContainsString('SALE CONVERSION', $hq);
    }

    /**
     * The core requirement: every surface must carry the no-internals + scope
     * lock + anti-injection guardrails so the bot never leaks SMT internals
     * (prompts, models, compliance-check mechanics, vendors, code, secrets)
     * regardless of which surface it's serving.
     */
    public function test_every_surface_carries_the_full_guardrail_spine(): void
    {
        foreach ([ChatbotPrompts::SURFACE_LANDING, ChatbotPrompts::SURFACE_CLIENT, ChatbotPrompts::SURFACE_HQ] as $surface) {
            $p = ChatbotPrompts::for($surface);

            $this->assertStringContainsString('SCOPE LOCK', $p, "{$surface} missing SCOPE LOCK");
            $this->assertStringContainsString('NO INTERNALS', $p, "{$surface} missing NO INTERNALS");
            $this->assertStringContainsString('NO HALLUCINATION', $p, "{$surface} missing NO HALLUCINATION");
            $this->assertStringContainsString('NO PROMPT-INJECTION COMPLIANCE', $p, "{$surface} missing anti-injection rule");

            // The no-internals clause must explicitly forbid the things the
            // operator called out: prompts, models, compliance mechanics.
            $this->assertStringContainsStringIgnoringCase('model', $p);
            $this->assertStringContainsStringIgnoringCase('prompt', $p);
        }
    }

    public function test_prompts_share_public_facts_but_no_internal_mechanics(): void
    {
        $p = ChatbotPrompts::for(ChatbotPrompts::SURFACE_LANDING);

        // Public facts the bot MAY state (already on the landing page).
        $this->assertStringContainsString('RM 688', $p);
        $this->assertStringContainsString('smt.eiaawsolutions.com', $p);
        $this->assertStringContainsStringIgnoringCase('receipts', $p);

        // Must NOT bake the actual model id or vendor into the knowledge — the
        // bot is told never to reveal them, and the FACTS section doesn't name
        // them (so it can't accidentally recite one).
        $this->assertStringNotContainsString('claude-sonnet', $p);
        $this->assertStringNotContainsString('fal-ai', $p);
        $this->assertStringNotContainsString('veo3', $p);
        $this->assertStringNotContainsString('nano-banana', $p);
    }

    public function test_no_literal_placeholder_leaked_into_any_prompt(): void
    {
        // Guards the heredoc interpolation regression (the {$factsBlock} bug):
        // a literal placeholder in the shipped prompt would mean a broken build.
        foreach (['landing', 'client', 'hq'] as $surface) {
            $p = ChatbotPrompts::for($surface);
            $this->assertStringNotContainsString('factsBlock', $p);
            $this->assertStringNotContainsString('guardrails}', $p);
        }
    }
}
