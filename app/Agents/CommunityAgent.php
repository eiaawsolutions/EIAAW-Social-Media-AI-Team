<?php

namespace App\Agents;

use App\Agents\Prompts\CommunityPrompt;
use App\Models\Brand;
use App\Models\InboxConversation;
use App\Models\InboxReplyDraft;
use App\Services\Inbox\InboxNormalizer;

/**
 * Drafts replies to inbound DMs and post comments. The L4 rung of the growth
 * ladder — the only actuator that reaches a specific person rather than
 * broadcasting at an audience.
 *
 * WAS A STUB until 2026-08-01. It returned fail() with "needs per-platform
 * webhook integration", and that assumption was wrong: Metricool's public API
 * exposes the whole inbox (read AND write) on the same shared token we already
 * publish with, verified by a proven send round-trip. Nothing platform-specific
 * was ever needed.
 *
 * SAFETY MODEL: this agent only ever WRITES A DRAFT. It never sends. Sending
 * requires a human approval (Filament inbox page) and runs in community:send.
 * That split is deliberate — a reply is addressed to a named individual, goes
 * out under the brand's name, and cannot be deleted afterwards, so a model's
 * confidence is not an acceptable gate on its own.
 *
 * Input: ['conversation_id' => int]
 */
class CommunityAgent extends BaseAgent
{
    /** Drafting needs the brand voice; it does not need a live connection. */
    protected array $requiredStages = ['brand_style'];

    /** Newest inbound messages to show the model, oldest of them first. */
    private const HISTORY_DEPTH = 8;

    public function role(): string { return 'community'; }
    public function promptVersion(): string { return CommunityPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $conversationId = (int) ($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            return AgentResult::fail('community: conversation_id is required.');
        }

        $conversation = InboxConversation::where('brand_id', $brand->id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            return AgentResult::fail("community: conversation #{$conversationId} not found for this brand.");
        }

        // Never spend a model call on a message the platform will not let us
        // answer. The window is a hard platform deadline, not a soft target.
        if (! $conversation->windowIsOpen()) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => 'reply window closed on '.$conversation->window_expires_at?->toDateTimeString(),
            ]);
        }

        if ($conversation->last_message_from_us) {
            return AgentResult::ok([
                'skipped' => true,
                'reason' => 'the last message in this thread is already ours',
            ]);
        }

        // One open draft per conversation. Re-drafting over an unapproved draft
        // would silently replace what the operator is looking at.
        $open = InboxReplyDraft::where('inbox_conversation_id', $conversation->id)
            ->whereIn('status', InboxReplyDraft::OPEN_STATUSES)
            ->exists();
        if ($open && empty($input['force'])) {
            return AgentResult::ok(['skipped' => true, 'reason' => 'a draft already exists for this conversation']);
        }

        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice has not been synthesised yet.');
        }

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: CommunityPrompt::system(),
            userMessage: $this->buildUserMessage($brand, $conversation, (string) $brandStyle->content_md),
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 1000,
            jsonSchema: CommunityPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! is_array($payload)) {
            return AgentResult::fail('Community reply drafting came back empty.');
        }

        $noReply = (bool) ($payload['recommends_no_reply'] ?? false);
        $body = trim((string) ($payload['body'] ?? ''));

        // A model that says "reply" but produces nothing to send is treated as a
        // no-reply recommendation rather than persisting an empty message that an
        // operator could approve into the void.
        if (! $noReply && $body === '') {
            $noReply = true;
        }

        $draft = InboxReplyDraft::create([
            'inbox_conversation_id' => $conversation->id,
            'brand_id' => $brand->id,
            'workspace_id' => $brand->workspace_id,
            'body' => $noReply ? '' : $body,
            'status' => InboxReplyDraft::STATUS_PENDING_APPROVAL,
            'recommends_no_reply' => $noReply,
            'reasoning' => isset($payload['reasoning']) ? (string) $payload['reasoning'] : null,
            'model_id' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => (float) ($result->costUsd ?? 0),
        ]);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'conversation_id' => $conversation->id,
            'recommends_no_reply' => $noReply,
            'chars' => mb_strlen($draft->body),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /**
     * The conversation as the model should see it: who wrote what, in order,
     * plus the brand's authoritative facts and voice.
     *
     * Message history is rendered oldest-first (readable as a conversation) even
     * though the normalizer sorts newest-first, and every message is labelled
     * with its author so the model cannot confuse our words for theirs.
     */
    private function buildUserMessage(Brand $brand, InboxConversation $conversation, string $brandStyleMd): string
    {
        $self = (string) (($conversation->raw['self'] ?? '') ?: '');
        $messages = array_reverse(array_slice(
            InboxNormalizer::sortedMessages($conversation->raw['messages'] ?? []),
            0,
            self::HISTORY_DEPTH,
        ));

        $lines = [];
        foreach ($messages as $m) {
            $who = ((string) ($m['from'] ?? '')) === $self ? 'BRAND' : 'THEM';
            $text = trim((string) ($m['text'] ?? ''));
            if ($text === '') {
                // Story mentions / reactions / attachments carry no text. Say so
                // explicitly — an empty line would read as an empty message and
                // invite the model to answer something that was never said.
                $text = count($m['attachments'] ?? []) > 0
                    ? '(sent an attachment, no text)'
                    : '(a story mention or reaction, no text)';
            }
            $lines[] = "{$who} [".($m['publicationDateTime'] ?? '?')."]: ".mb_substr($text, 0, 600);
        }

        if ($lines === []) {
            $text = trim((string) ($conversation->last_message_text ?? ''));
            $lines[] = 'THEM: '.($text !== '' ? mb_substr($text, 0, 600) : '(no text content)');
        }

        $history = implode("\n", $lines);
        $surface = $conversation->conversation_type === InboxConversation::TYPE_COMMENT
            ? 'a PUBLIC comment on one of the brand\'s posts (anyone can read your reply)'
            : 'a PRIVATE direct message';
        $person = $conversation->participant_name ? "@{$conversation->participant_name}" : 'this person';
        $factsBlock = $brand->brandFactsBlock();
        $factsSection = $factsBlock === '' ? '' : "\n{$factsBlock}\n";

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}
PLATFORM: {$conversation->provider}
SURFACE: This is {$surface}.
PERSON: {$person}
{$factsSection}
# Conversation (oldest first; BRAND = us, THEM = the person)
{$history}

# brand-style.md (voice — single source of truth)
{$brandStyleMd}

Draft the brand's reply to the most recent THEM message, following every hard rule. If the facts above do not answer what they asked, do not invent one. Output only the JSON.
MSG;
    }
}
