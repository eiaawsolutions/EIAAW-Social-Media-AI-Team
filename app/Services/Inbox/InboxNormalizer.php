<?php

namespace App\Services\Inbox;

use App\Models\InboxConversation;
use Illuminate\Support\Carbon;

/**
 * Turns a raw Metricool inbox row into the columns we store.
 *
 * Pure and separately testable, because the two shapes differ in ways that are
 * easy to get subtly wrong and expensive to get wrong live:
 *
 *   DM (/v2/inbox/conversations) — has `self`, `participants[]` and an EMBEDDED
 *       `messages[]`. The reply endpoint needs BOTH the conversation id and the
 *       other participant's id as `recipient`. Ordering of messages[] is not
 *       guaranteed, so "the last message" must be derived by timestamp, not by
 *       taking the first or last array element (a first attempt at this read the
 *       oldest two messages and appeared to show a send had failed when it had
 *       actually succeeded).
 *
 *   Comment (/v2/inbox/post-comments) — the id IS the reply endpoint's
 *       `objectId`, and there is no recipient.
 *
 * Everything here degrades to null rather than guessing. A missing timestamp
 * means an unknown reply window, and an unknown window must never render as an
 * imminent deadline.
 */
final class InboxNormalizer
{
    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null  null when the row lacks an id to key on
     */
    public static function normalize(array $row, string $type): ?array
    {
        $externalId = trim((string) ($row['id'] ?? ''));
        if ($externalId === '') {
            return null;
        }

        $self = (string) ($row['self'] ?? '');
        $messages = self::sortedMessages($row['messages'] ?? []);
        $last = $messages !== [] ? $messages[0] : null;

        $lastAt = self::parseDate(
            $last['publicationDateTime']
                ?? $row['lastUpdateTime']
                ?? $row['creationDate']
                ?? null,
        );

        // A comment row carries its text at the top level; a DM carries it on
        // the newest message. Story mentions and reactions have no text at all —
        // represent that as null, never as an empty-string "message".
        $text = self::firstNonEmpty([
            $last['text'] ?? null,
            $row['text'] ?? null,
            $row['message'] ?? null,
        ]);

        return [
            'conversation_type' => $type,
            'provider' => (string) ($row['provider'] ?? ''),
            'external_id' => $externalId,
            'recipient_external_id' => $type === InboxConversation::TYPE_DM
                ? self::otherParticipantId($row, $self)
                : null,
            'participant_name' => self::otherParticipantName($row, $self),
            'status' => strtoupper((string) ($row['status'] ?? InboxConversation::STATUS_PENDING)),
            'last_message_text' => $text,
            'last_message_from_us' => $last !== null && $self !== '' && (string) ($last['from'] ?? '') === $self,
            'last_message_at' => $lastAt,
            'window_expires_at' => InboxConversation::windowExpiryFor($type, $lastAt),
            'message_count' => count($messages),
            'raw' => $row,
        ];
    }

    /**
     * Messages newest-first. Metricool does not document an order and prod
     * returned them oldest-last in one place and not in another — sort rather
     * than trust position.
     *
     * @param  mixed  $messages
     * @return array<int,array<string,mixed>>
     */
    public static function sortedMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }
        $rows = array_values(array_filter($messages, 'is_array'));
        usort($rows, fn ($a, $b) => strcmp(
            (string) ($b['publicationDateTime'] ?? ''),
            (string) ($a['publicationDateTime'] ?? ''),
        ));

        return $rows;
    }

    /** The participant who is NOT us — the `recipient` a DM reply requires. */
    private static function otherParticipantId(array $row, string $self): ?string
    {
        foreach (($row['participants'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (string) ($p['id'] ?? '');
            if ($id !== '' && $id !== $self) {
                return $id;
            }
        }

        return null;
    }

    private static function otherParticipantName(array $row, string $self): ?string
    {
        foreach (($row['participants'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            if ((string) ($p['id'] ?? '') !== $self) {
                $name = trim((string) ($p['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        // Comment rows carry the author differently across providers.
        foreach (['from', 'author', 'authorName', 'username'] as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_array($v) && trim((string) ($v['name'] ?? '')) !== '') {
                return trim((string) $v['name']);
            }
        }

        return null;
    }

    /** @param array<int,mixed> $candidates */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return null;
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
