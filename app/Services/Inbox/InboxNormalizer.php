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
     * @param  string  $queriedProvider  the provider we ASKED for. Used as the
     *   fallback when the row omits its own `provider`: that column is posted
     *   straight back to Metricool on reply, and an empty one produces a
     *   permanently-failed send (STATUS_FAILED has no retry path).
     * @return array<string,mixed>|null  null when the row lacks an id to key on
     */
    public static function normalize(array $row, string $type, string $queriedProvider = ''): ?array
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
            'provider' => trim((string) ($row['provider'] ?? '')) ?: strtoupper(trim($queriedProvider)),
            'external_id' => $externalId,
            'recipient_external_id' => $type === InboxConversation::TYPE_DM
                ? self::otherParticipantId($row, $self)
                : null,
            'participant_name' => self::otherParticipantName($row, $self),
            'status' => strtoupper((string) ($row['status'] ?? InboxConversation::STATUS_PENDING)),
            'last_message_text' => $text,
            'last_message_from_us' => self::lastMessageIsOurs($row, $type, $self, $last),
            'last_message_at' => $lastAt,
            'window_expires_at' => InboxConversation::windowExpiryFor($type, $lastAt),
            // A comment row is one message; only DM rows carry a thread.
            'message_count' => $type === InboxConversation::TYPE_DM ? count($messages) : 1,
            'raw' => $row,
        ];
    }

    /**
     * Did WE write the most recent thing in this row?
     *
     * DM rows answer this from the newest embedded message's `from`. COMMENT
     * rows have no `messages[]` at all, so the naive version was structurally
     * always false for every comment — which meant `scopeAwaitingReply` could
     * never exclude a comment, CommunityAgent's "already ours" guard could never
     * fire on one, and the brand could end up publicly replying to its own
     * comment. Comment rows DO carry `self` and an author field, so the check is
     * perfectly possible; it simply wasn't done.
     *
     * @param  array<string,mixed>       $row
     * @param  array<string,mixed>|null  $last  newest DM message, if any
     */
    private static function lastMessageIsOurs(array $row, string $type, string $self, ?array $last): bool
    {
        if ($self === '') {
            return false;
        }

        if ($type === InboxConversation::TYPE_DM) {
            return $last !== null && (string) ($last['from'] ?? '') === $self;
        }

        $author = self::authorId($row);

        return $author !== null && $author === $self;
    }

    /** Author id of a comment/review row, across the shapes providers use. */
    private static function authorId(array $row): ?string
    {
        foreach (['from', 'author', 'authorId', 'userId', 'fromId'] as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_array($v)) {
                $id = trim((string) ($v['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
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
