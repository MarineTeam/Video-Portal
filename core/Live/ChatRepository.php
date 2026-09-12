<?php

declare(strict_types=1);

namespace Portal\Live;

use Portal\Db;

/**
 * Reading and writing a live stream's chat.
 *
 * Three rules live here rather than in the controller, because a rule in a
 * controller is a rule the next controller forgets:
 *
 *   HIDING KEEPS THE MESSAGE. There is no delete path in this class at all.
 *   Nothing removes a row, so the decision cannot be lost and the body is still
 *   there to compare against.
 *
 *   A MUTE IS PER STREAM. Every mute query names a stream; there is no
 *   signature here that could express a site-wide ban.
 *
 *   THE CURSOR IS AN ID. Never a timestamp: DATETIME holds whole seconds, so
 *   two messages in the same second make a timestamp cursor either repeat one
 *   or lose one — which is exactly the bug the calendar device sync had to be
 *   wound two seconds back to survive. An id cannot tie.
 */
final class ChatRepository
{
    /** Messages handed to a client in one poll. */
    public const PER_POLL = 100;

    public function __construct(private readonly Db $db)
    {
    }

    // ---------------------------------------------------------------- reading

    /**
     * Messages in this stream after $afterId, oldest first.
     *
     * Hidden ones are absent. A moderator's page uses withHidden() instead, so
     * the ordinary path cannot accidentally serve something somebody decided
     * about — the filter is in the query rather than applied by a caller.
     *
     * Oldest first because a chat reads downwards and a client appends. Handing
     * back newest-first would make every consumer reverse it, and one of them
     * eventually would not.
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $streamId, int $afterId = 0, int $limit = self::PER_POLL): array
    {
        return $this->db->all(
            'SELECT id, user_id, author, body, created_at
               FROM {live_chat_messages}
              WHERE stream_id = ? AND id > ? AND hidden_at IS NULL
              ORDER BY id
              LIMIT ' . max(1, min($limit, self::PER_POLL)),
            [$streamId, max(0, $afterId)]
        );
    }

    /**
     * The tail of the room, for somebody arriving.
     *
     * The last N messages, then flipped, so a newcomer sees recent context
     * rather than the first hundred things said before they got here.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $streamId, int $limit = 50): array
    {
        $rows = $this->db->all(
            'SELECT id, user_id, author, body, created_at
               FROM {live_chat_messages}
              WHERE stream_id = ? AND hidden_at IS NULL
              ORDER BY id DESC
              LIMIT ' . max(1, min($limit, self::PER_POLL)),
            [$streamId]
        );

        return array_reverse($rows);
    }

    /**
     * Everything in the room including what was hidden, for a moderator.
     *
     * Carries hidden_at and hidden_by, so the screen can show that a decision
     * was made and by whom — a hidden message rendered identically to a live
     * one would have two moderators undoing each other.
     *
     * @return list<array<string, mixed>>
     */
    public function withHidden(int $streamId, int $limit = self::PER_POLL): array
    {
        $rows = $this->db->all(
            'SELECT id, user_id, author, body, created_at, hidden_at, hidden_by
               FROM {live_chat_messages}
              WHERE stream_id = ?
              ORDER BY id DESC
              LIMIT ' . max(1, min($limit, self::PER_POLL)),
            [$streamId]
        );

        return array_reverse($rows);
    }

    /*
     * There was a latestId() here — "the newest id in the room, so a client can
     * start at the end" — and it was written before it was needed and then
     * never needed, which in this codebase is a shape with a history.
     *
     * Nothing wants it. The page takes its cursor from the last row recent()
     * already returned, which is the same number for one fewer query; and a
     * client with no cursor is meant to get the tail rather than skip it, since
     * somebody arriving mid-service wants to see what was just said.
     *
     * Deleted rather than left, with the reasoning here, because "what is the
     * newest id" reads like an obvious accessor and would be rebuilt — and the
     * version somebody reaches for in a poll loop is an extra query per person
     * per four seconds.
     */

    /**
     * When this person last posted in this stream, or null.
     *
     * What slow mode is measured from. HIDDEN MESSAGES COUNT — the timestamp is
     * read without filtering on hidden_at, because a message a moderator
     * removed was still sent, and not counting it would hand somebody a free
     * post every time one of theirs was taken down. That is the wrong way for
     * the incentive to point.
     */
    public function lastPostedAt(int $streamId, int $userId): ?string
    {
        $value = $this->db->value(
            'SELECT MAX(created_at) FROM {live_chat_messages}
              WHERE stream_id = ? AND user_id = ?',
            [$streamId, $userId]
        );

        return is_string($value) ? $value : null;
    }

    // ---------------------------------------------------------------- writing

    /**
     * Say something.
     *
     * Every gate — the window, the mute, slow mode, the resend — is decided by
     * the caller and checked here as well, because this is the only way a row
     * gets in and a second caller will eventually arrive.
     *
     * @return int the new message id
     */
    public function post(int $streamId, int $userId, string $author, string $body): int
    {
        return $this->db->insert('live_chat_messages', [
            'stream_id'  => $streamId,
            'user_id'    => $userId,
            'author'     => mb_substr(trim($author), 0, 190),
            'body'       => self::clean($body),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Whether this person is trying to send something a moderator already hid.
     *
     * THIS IS WHY HIDING KEEPS THE MESSAGE. Without the row there is nothing to
     * compare against, and the obvious next move for somebody whose message
     * disappeared is to send it again — which would work, and keep working, and
     * the moderator would be the only person in the room doing any work.
     *
     * Compared on the cleaned body, so whitespace and case do not defeat it.
     * Scoped to this person in this stream: two people independently saying the
     * same short thing is a coincidence, not a resend, and refusing the second
     * one would be silencing somebody for what a stranger typed.
     */
    public function wasHidden(int $streamId, int $userId, string $body): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {live_chat_messages}
              WHERE stream_id = ? AND user_id = ?
                AND hidden_at IS NOT NULL
                AND LOWER(body) = LOWER(?)',
            [$streamId, $userId, self::clean($body)]
        ) > 0;
    }

    // ------------------------------------------------------------- moderating

    /**
     * Take a message off the room, keeping it.
     *
     * A conditional UPDATE, so hiding something already hidden does not move
     * the timestamp or rewrite who decided. Two moderators pressing the button
     * within a second of each other is the ordinary case in a busy room, and
     * the second must not overwrite the first's name on the decision.
     *
     * @return bool whether this call was the one that hid it
     */
    public function hide(int $messageId, string $by): bool
    {
        return $this->db->execute(
            'UPDATE {live_chat_messages}
                SET hidden_at = NOW(), hidden_by = ?
              WHERE id = ? AND hidden_at IS NULL',
            [mb_substr(trim($by), 0, 190) ?: null, $messageId]
        ) > 0;
    }

    /**
     * Put it back.
     *
     * Offered because a moderator hides the wrong message sooner or later, and
     * a decision that cannot be reversed is one people are afraid to make
     * quickly — which in a live room means not making it. hidden_by is cleared
     * with it: the record of who hid it is only useful while it IS hidden, and
     * leaving a name on a visible message reads as an accusation.
     */
    public function unhide(int $messageId): bool
    {
        return $this->db->execute(
            'UPDATE {live_chat_messages}
                SET hidden_at = NULL, hidden_by = NULL
              WHERE id = ? AND hidden_at IS NOT NULL',
            [$messageId]
        ) > 0;
    }

    /**
     * Stop somebody talking in THIS stream.
     *
     * INSERT IGNORE against the composite primary key, so muting twice is not
     * an error — the moderator pressing it again because nothing visibly
     * happened must not create a second row that a later unmute leaves behind.
     */
    public function mute(int $streamId, int $userId, string $by, string $reason = ''): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO {live_chat_mutes}
                 (stream_id, user_id, muted_by, reason, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [
                $streamId,
                $userId,
                mb_substr(trim($by), 0, 190) ?: null,
                mb_substr(trim($reason), 0, 190) ?: null,
                date('Y-m-d H:i:s'),
            ]
        );
    }

    public function unmute(int $streamId, int $userId): void
    {
        $this->db->execute(
            'DELETE FROM {live_chat_mutes} WHERE stream_id = ? AND user_id = ?',
            [$streamId, $userId]
        );
    }

    /**
     * Whether this person is muted in this stream.
     *
     * The stream is not optional and there is no overload that omits it. A mute
     * is a decision about tonight, and the signature is what stops it quietly
     * becoming a decision about every stream this site will ever run.
     */
    public function isMuted(int $streamId, int $userId): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {live_chat_mutes} WHERE stream_id = ? AND user_id = ?',
            [$streamId, $userId]
        ) > 0;
    }

    /**
     * Who is muted in this stream.
     *
     * @return list<array<string, mixed>>
     */
    public function mutes(int $streamId): array
    {
        return $this->db->all(
            'SELECT m.user_id, m.muted_by, m.reason, m.created_at,
                    u.name, u.email
               FROM {live_chat_mutes} m
               LEFT JOIN {users} u ON u.id = m.user_id
              WHERE m.stream_id = ?
              ORDER BY m.created_at DESC',
            [$streamId]
        );
    }

    /**
     * A message body, made storable.
     *
     * Control characters out, whitespace collapsed, and a hard length. The
     * collapse is not tidiness: a hundred newlines is a message that scrolls
     * everybody else's off the screen, which is the cheapest form of shouting
     * available in a room with no formatting.
     *
     * `\p{Z}` as well as `\s`, and that is not belt-and-braces. PHP's `/u`
     * turns on UTF-8 but NOT PCRE_UCP, so `\s` stays ASCII — a non-breaking
     * space is not collapsed by it, and two hundred of those is the same
     * scrolling attack in a character the naive pattern cannot see.
     *
     * The bidi overrides go entirely. They have no legitimate use in a line of
     * chat and they reorder the text AROUND them, so one dropped into a message
     * garbles the display name beside it.
     */
    public static function clean(string $body): string
    {
        $body = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
        $body = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body);
        $body = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $body);

        return mb_substr(trim($body), 0, 500);
    }

    /**
     * Whether there is actually anything in this message.
     *
     * A SEPARATE question from clean(), and the separation is the point.
     *
     * A body of nothing but zero-width spaces survives clean() — U+200B is
     * neither whitespace nor a separator, it is a FORMAT character — and posts
     * as a blank line. Repeat that and it is the scrolling attack again,
     * wearing the one costume the collapse cannot strip.
     *
     * So the format characters are removed HERE, for the emptiness test only,
     * rather than in clean(). Removing them there would break emoji: a family
     * or a profession is several code points joined by U+200D, and stripping
     * that turns one emoji into three or four. Somebody sending 👨‍👩‍👧 is not
     * attacking the room, and the message they get back should not be their
     * emoji taken apart.
     */
    public static function isEmpty(string $body): bool
    {
        $stripped = (string) preg_replace('/[\p{Cf}\s\p{Z}]+/u', '', self::clean($body));

        return $stripped === '';
    }
}
