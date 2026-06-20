<?php

namespace Tests\Unit;

use App\Agents\SchedulerAgent;
use App\Models\Brand;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1 fix — SchedulerAgent had no functional unit coverage (only a security
 * isolation test). These lock its DB-free decision logic: the schedulable-status
 * gate (which statuses may be (re)scheduled) and the missing-input guard that
 * fails before any DB access. The DB-write happy path runs against prod-pointing
 * .env locally, so it is covered by the live smoke, not here.
 */
class SchedulerAgentTest extends TestCase
{
    public function test_only_approved_or_scheduled_statuses_are_schedulable(): void
    {
        $this->assertTrue(SchedulerAgent::isSchedulableStatus('approved'));
        $this->assertTrue(SchedulerAgent::isSchedulableStatus('scheduled'));

        foreach (['draft', 'compliance_pending', 'compliance_failed', 'rejected', 'published', '', null] as $bad) {
            $this->assertFalse(SchedulerAgent::isSchedulableStatus($bad), "status '{$bad}' must not be schedulable");
        }
    }

    public function test_handle_requires_draft_id_and_scheduled_for(): void
    {
        $agent = new SchedulerAgent(new LlmGateway);
        $handle = new ReflectionMethod($agent, 'handle');

        // Missing both — fails before touching the DB.
        $result = $handle->invoke($agent, new Brand, []);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('draft_id and scheduled_for', $result->errorMessage);

        // Missing scheduled_for only — same guard.
        $result = $handle->invoke($agent, new Brand, ['draft_id' => 1]);
        $this->assertFalse($result->ok);
    }
}
