<?php

namespace Tests\Unit;

use App\Services\Imagery\FalAiClient;
use Tests\TestCase;

/**
 * Guards FalAiClient::isContentPolicyBody — the classifier that turns a Veo
 * safety refusal into the typed FalContentPolicyException so VideoAgent can
 * retry as text-to-video (drop the flagged keyframe) instead of hard-failing.
 *
 * The body strings here are the REAL ones observed live 2026-06-01/02 on the
 * fal-ai/veo3/fast/image-to-video endpoint.
 */
class FalContentPolicyDetectionTest extends TestCase
{
    private const POLICY_422_VIOLATION = '{"detail":[{"loc":["body","prompt"],"msg":"The content could not be processed because it contained material flagged by a content checker.","type":"content_policy_violation"}]}';
    private const POLICY_422_UNSAFE = '{"detail":[{"loc":["body"],"msg":"The model did not generate the expected output for this prompt. This may occur for several reasons, including unsafe content, a prompt that is incomplete..."}]}';
    private const POLICY_422_COULDNOT = '{"detail":[{"loc":["prompt"],"msg":"Could not generate images with the given prompts and images. Please try again with different inputs.","type":"invalid_request"}]}';

    public function test_detects_content_policy_violation_bodies(): void
    {
        $this->assertTrue(FalAiClient::isContentPolicyBody(422, self::POLICY_422_VIOLATION));
        $this->assertTrue(FalAiClient::isContentPolicyBody(422, self::POLICY_422_UNSAFE));
        $this->assertTrue(FalAiClient::isContentPolicyBody(422, self::POLICY_422_COULDNOT));
        // Case-insensitive + the generic "content checker" phrasing.
        $this->assertTrue(FalAiClient::isContentPolicyBody(422, 'Material FLAGGED BY A CONTENT checker'));
    }

    public function test_ignores_non_policy_failures(): void
    {
        // A transient 5xx or a plain bad-request is NOT a content-policy refusal.
        $this->assertFalse(FalAiClient::isContentPolicyBody(500, 'internal server error'));
        $this->assertFalse(FalAiClient::isContentPolicyBody(422, '{"detail":"duration must be one of 4s,6s,8s"}'));
        $this->assertFalse(FalAiClient::isContentPolicyBody(429, 'rate limited'));
        // Account-lockout bodies are handled by isAccountLockoutBody, not here.
        $this->assertFalse(FalAiClient::isContentPolicyBody(403, 'User is locked: top up your balance'));
    }

    public function test_only_422_and_400_qualify(): void
    {
        // The policy phrasing only counts on the statuses Veo actually uses for it.
        $this->assertFalse(FalAiClient::isContentPolicyBody(200, 'content_policy_violation'));
        $this->assertTrue(FalAiClient::isContentPolicyBody(400, 'content_policy_violation'));
        $this->assertTrue(FalAiClient::isContentPolicyBody(422, 'content_policy_violation'));
    }
}
