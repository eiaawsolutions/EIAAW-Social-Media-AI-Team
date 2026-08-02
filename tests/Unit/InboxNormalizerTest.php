<?php

namespace Tests\Unit;

use App\Models\InboxConversation;
use App\Services\Inbox\InboxNormalizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure tests for the Metricool inbox row → our columns mapping.
 *
 * Fixtures are shaped from REAL prod payloads captured 2026-08-01 (an Instagram
 * story-mention thread on brand#1 and comment rows on brand#8), because the two
 * row shapes differ in ways that are easy to get quietly wrong: DMs carry
 * `self` + `participants[]` + embedded `messages[]` and need a recipient id to
 * reply; comments carry their text at the top level and need none.
 */
class InboxNormalizerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function dmRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'aWdfZAG06MTpJR01lc3NhZ',
            'self' => '17841424103721687',
            'provider' => 'INSTAGRAM',
            'status' => 'PENDING',
            'creationDate' => '2026-05-23T17:25:08+0200',
            'lastUpdateTime' => '2026-07-28T14:34:30+0200',
            'participants' => [
                ['id' => '17841424103721687', 'name' => 'eiaawsolutions'],
                ['id' => '1872531420103716', 'name' => 'amoswafula'],
            ],
            'messages' => [
                ['id' => 'm1', 'from' => '1872531420103716', 'to' => '17841424103721687', 'text' => 'Do you do this for cafes?', 'publicationDateTime' => '2026-07-28T14:25:44+0200', 'attachments' => []],
                ['id' => 'm0', 'from' => '1872531420103716', 'to' => '17841424103721687', 'text' => 'hi there', 'publicationDateTime' => '2026-05-23T17:25:08+0200', 'attachments' => []],
            ],
        ], $overrides);
    }

    public function test_dm_maps_recipient_to_the_other_participant(): void
    {
        // The reply endpoint requires the OTHER participant's id. Passing our own
        // `self` id would address the message to ourselves.
        $out = InboxNormalizer::normalize($this->dmRow(), InboxConversation::TYPE_DM);

        $this->assertSame('1872531420103716', $out['recipient_external_id']);
        $this->assertSame('amoswafula', $out['participant_name']);
    }

    public function test_newest_message_wins_regardless_of_array_order(): void
    {
        // Metricool does not document ordering. An early version of this code
        // read positionally and reported a successful send as a failure.
        $row = $this->dmRow();
        $row['messages'] = array_reverse($row['messages']); // oldest first now

        $out = InboxNormalizer::normalize($row, InboxConversation::TYPE_DM);

        $this->assertSame('Do you do this for cafes?', $out['last_message_text']);
    }

    public function test_detects_when_the_last_message_is_ours(): void
    {
        $row = $this->dmRow();
        $row['messages'][] = [
            'id' => 'm2', 'from' => '17841424103721687', 'to' => '1872531420103716',
            'text' => 'We do!', 'publicationDateTime' => '2026-07-29T09:00:00+0200', 'attachments' => [],
        ];

        $out = InboxNormalizer::normalize($row, InboxConversation::TYPE_DM);

        $this->assertTrue($out['last_message_from_us'], 'a thread we already answered must not be drafted for');
    }

    public function test_textless_story_mention_yields_null_not_empty_string(): void
    {
        // Prod brand#1's thread was entirely story mentions with text:"". An
        // empty string would render as a real (blank) message and invite a reply
        // to something nobody said.
        $row = $this->dmRow();
        $row['messages'] = [
            ['id' => 'm1', 'from' => '1872531420103716', 'text' => '', 'publicationDateTime' => '2026-07-28T14:25:44+0200', 'attachments' => []],
        ];

        $out = InboxNormalizer::normalize($row, InboxConversation::TYPE_DM);

        $this->assertNull($out['last_message_text']);
    }

    public function test_dm_window_is_seven_days_from_the_last_message(): void
    {
        $out = InboxNormalizer::normalize($this->dmRow(), InboxConversation::TYPE_DM);

        $this->assertSame(
            Carbon::parse('2026-07-28T14:25:44+0200')->addHours(168)->timestamp,
            $out['window_expires_at']->timestamp,
        );
    }

    public function test_comment_window_is_twenty_four_hours(): void
    {
        $out = InboxNormalizer::normalize([
            'id' => '18549254629071887',
            'self' => '17841467627357941',
            'provider' => 'INSTAGRAM',
            'status' => 'PENDING',
            'text' => 'is this open on sunday?',
            'creationDate' => '2026-08-01T10:00:00+0200',
        ], InboxConversation::TYPE_COMMENT);

        $this->assertSame('is this open on sunday?', $out['last_message_text']);
        $this->assertNull($out['recipient_external_id'], 'comment replies take no recipient');
        $this->assertSame(
            Carbon::parse('2026-08-01T10:00:00+0200')->addHours(24)->timestamp,
            $out['window_expires_at']->timestamp,
        );
    }

    public function test_unknown_timestamp_yields_unknown_window_not_an_imminent_one(): void
    {
        $out = InboxNormalizer::normalize([
            'id' => 'x1', 'provider' => 'INSTAGRAM', 'status' => 'PENDING',
        ], InboxConversation::TYPE_COMMENT);

        $this->assertNull($out['window_expires_at']);
        $this->assertNull($out['last_message_at']);
    }

    public function test_row_without_an_id_is_dropped(): void
    {
        $this->assertNull(InboxNormalizer::normalize(['provider' => 'INSTAGRAM'], InboxConversation::TYPE_DM));
    }

    public function test_window_expiry_helper_is_pure_and_null_safe(): void
    {
        $this->assertNull(InboxConversation::windowExpiryFor(InboxConversation::TYPE_DM, null));
        $this->assertNull(InboxConversation::windowExpiryFor('nonsense', Carbon::parse('2026-08-01T00:00:00Z')));

        $at = Carbon::parse('2026-08-01T00:00:00Z');
        $this->assertSame(24, (int) $at->diffInHours(
            InboxConversation::windowExpiryFor(InboxConversation::TYPE_COMMENT, $at),
        ));
    }

    public function test_window_open_check_treats_unknown_as_open(): void
    {
        // Refusing to reply because we don't know the deadline would drop real
        // conversations; the platform is the backstop if we're wrong.
        $c = new InboxConversation(['conversation_type' => InboxConversation::TYPE_DM]);
        $this->assertTrue($c->windowIsOpen());

        $c->window_expires_at = Carbon::now()->subHour();
        $this->assertFalse($c->windowIsOpen());

        $c->window_expires_at = Carbon::now()->addHour();
        $this->assertTrue($c->windowIsOpen());
    }

    public function test_sorted_messages_tolerates_garbage(): void
    {
        $this->assertSame([], InboxNormalizer::sortedMessages(null));
        $this->assertSame([], InboxNormalizer::sortedMessages('nope'));
        $this->assertCount(1, InboxNormalizer::sortedMessages([['id' => 'a'], 'junk', 42]));
    }
}
