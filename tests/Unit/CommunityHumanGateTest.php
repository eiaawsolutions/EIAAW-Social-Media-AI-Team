<?php

namespace Tests\Unit;

use App\Agents\CommunityAgent;
use App\Agents\Prompts\CommunityPrompt;
use App\Console\Commands\CommunityDraft;
use App\Console\Commands\CommunityIngest;
use App\Console\Commands\CommunitySend;
use App\Models\InboxReplyDraft;
use Tests\TestCase;

/**
 * Locks the safety model of the L4 actuator.
 *
 * Sending a reply is the single most irreversible thing this system does: it
 * addresses a named individual, goes out under the brand's name, and cannot be
 * deleted afterwards (we hold no platform tokens — the same constraint that
 * makes published posts unretractable). So the invariant is not "the agent is
 * careful", it is "there is no code path from drafted to sent that skips a
 * human". These tests assert that structurally, from source, so a future change
 * that quietly wires up auto-send fails CI instead of shipping.
 */
class CommunityHumanGateTest extends TestCase
{
    private function source(string $class): string
    {
        return (string) file_get_contents((new \ReflectionClass($class))->getFileName());
    }

    public function test_agent_only_ever_creates_pending_approval_drafts(): void
    {
        $src = $this->source(CommunityAgent::class);

        $this->assertStringContainsString('STATUS_PENDING_APPROVAL', $src);

        foreach (['STATUS_APPROVED', 'STATUS_SENT'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $src,
                "CommunityAgent must never set {$forbidden} — only a human may approve, and only community:send may mark sent",
            );
        }
    }

    public function test_agent_never_calls_a_send_method(): void
    {
        $src = $this->source(CommunityAgent::class);

        foreach (['replyToConversation', 'replyToPostComment', 'MetricoolClient'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $src,
                'the drafting agent must have no route to the platform at all',
            );
        }
    }

    public function test_drafting_command_does_not_send(): void
    {
        $src = $this->source(CommunityDraft::class);

        foreach (['replyToConversation', 'replyToPostComment', 'STATUS_APPROVED'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src);
        }
    }

    public function test_ingest_is_read_only(): void
    {
        $src = $this->source(CommunityIngest::class);

        foreach (['replyToConversation', 'replyToPostComment'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, 'ingest must never write to the platform');
        }
    }

    public function test_send_command_only_picks_up_approved_drafts(): void
    {
        $src = $this->source(CommunitySend::class);

        $this->assertStringContainsString('sendable()', $src);
        $this->assertStringNotContainsString(
            'STATUS_PENDING_APPROVAL',
            $src,
            'community:send must never select an unapproved draft',
        );
    }

    public function test_sendable_scope_requires_approved_and_excludes_no_reply(): void
    {
        $sql = InboxReplyDraft::query()->sendable()->toSql();
        $bindings = InboxReplyDraft::query()->sendable()->getBindings();

        $this->assertStringContainsString('status', $sql);
        $this->assertContains(InboxReplyDraft::STATUS_APPROVED, $bindings);

        // A "no reply recommended" draft has an empty body — approving one by
        // accident must not send an empty message.
        $this->assertStringContainsString('recommends_no_reply', $sql);
    }

    public function test_send_rechecks_the_window_before_delivering(): void
    {
        // A draft approved 20 hours ago on a 24h comment window may have expired
        // while queued. Sending it would fail at the platform anyway.
        $this->assertStringContainsString('windowIsOpen', $this->source(CommunitySend::class));
    }

    public function test_prompt_forbids_invention_and_system_disclosure(): void
    {
        $system = CommunityPrompt::system();

        foreach (['MUST NOT invent', 'NEVER claim an action has been taken', 'no mention of AI'] as $needle) {
            $this->assertStringContainsString($needle, $system);
        }
    }

    public function test_prompt_makes_no_reply_a_first_class_outcome(): void
    {
        // Without this the model pads out a pleasantry for spam and story
        // reactions, and the operator learns to rubber-stamp the queue.
        $this->assertStringContainsString('recommends_no_reply', CommunityPrompt::system());
        $this->assertContains('recommends_no_reply', CommunityPrompt::schema()['required']);
        $this->assertStringContainsString('correct, successful outcome', CommunityPrompt::system());
    }

    public function test_open_statuses_block_duplicate_drafting(): void
    {
        // Re-drafting over a draft the operator is currently reading would
        // silently swap what they are about to approve.
        $this->assertSame(
            [
                InboxReplyDraft::STATUS_PENDING_APPROVAL,
                InboxReplyDraft::STATUS_APPROVED,
                InboxReplyDraft::STATUS_SENT,
            ],
            InboxReplyDraft::OPEN_STATUSES,
        );
    }
}
