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
            // Added with the self-service cancellation facts: describing a public
            // process must never let the bot read or change a real account.
            $this->assertStringContainsString('NO ACTIONS, NO ACCOUNT STATE', $p, "{$surface} missing account-action guard");

            // The no-internals clause must explicitly forbid the things the
            // operator called out: prompts, models, compliance mechanics.
            $this->assertStringContainsStringIgnoringCase('model', $p);
            $this->assertStringContainsStringIgnoringCase('prompt', $p);
        }
    }

    /**
     * Every surface must be able to describe the (public, self-service)
     * cancellation/pause process — the screenshot bug was the guide dead-ending
     * "how do I cancel?" — but ONLY at the level of "use the Billing page",
     * never by acting on or reading the account.
     */
    public function test_every_surface_can_describe_self_service_cancellation(): void
    {
        foreach ([ChatbotPrompts::SURFACE_LANDING, ChatbotPrompts::SURFACE_CLIENT, ChatbotPrompts::SURFACE_HQ] as $surface) {
            $p = ChatbotPrompts::for($surface);

            $this->assertStringContainsStringIgnoringCase('cancel', $p, "{$surface} can't describe cancellation");
            $this->assertStringContainsStringIgnoringCase('Billing', $p, "{$surface} doesn't point cancellation at Billing");
        }

        // The facts must describe cancel-at-period-end + reactivate/pause so the
        // bot states the real (CPA-fair) behaviour, not "cut off immediately".
        $client = ChatbotPrompts::for(ChatbotPrompts::SURFACE_CLIENT);
        $this->assertStringContainsStringIgnoringCase('period', $client);
        $this->assertStringContainsStringIgnoringCase('reactivate', $client);
        $this->assertStringContainsStringIgnoringCase('pause', $client);
    }

    public function test_prompt_version_is_stamped_and_bumped(): void
    {
        // The version is appended to every ai_costs row; a prompt change must
        // bump it. Lock that it moved past v1 (the version shipped before the
        // cancellation facts) and stays a 'support.chatbot.*' token.
        $this->assertStringStartsWith('support.chatbot.', ChatbotPrompts::PROMPT_VERSION);
        $this->assertNotSame('support.chatbot.v1', ChatbotPrompts::PROMPT_VERSION);
    }

    public function test_prompts_share_public_facts_but_no_internal_mechanics(): void
    {
        $p = ChatbotPrompts::for(ChatbotPrompts::SURFACE_LANDING);

        // Public facts the bot MAY state (already on the landing page).
        $this->assertStringContainsString('RM 688', $p);
        $this->assertStringContainsString('smt.eiaawsolutions.com', $p);
        $this->assertStringContainsStringIgnoringCase('receipts', $p);
        // Enterprise tier must be mentioned so the bot routes Enterprise asks to
        // "Talk to us" rather than dead-ending — guards the prompt drift point.
        $this->assertStringContainsStringIgnoringCase('Enterprise', $p);
        // New caps must be the ones quoted (guards the hardcoded-facts drift).
        $this->assertStringContainsString('25 AI image posts', $p);
        $this->assertStringContainsString('4 AI video posts', $p);

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
