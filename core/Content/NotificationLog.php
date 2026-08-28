<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * The record of what this site has told somebody.
 *
 * Written alongside every send rather than instead of one: the channels are
 * still the delivery, this is only the receipt. That ordering matters — a
 * failure to record must never stop a notification going out, which is why
 * record() swallows its own errors and says so.
 */
final class NotificationLog
{
    public const EMAIL = 'email';
    public const PUSH = 'push';

    /** Nobody reads a list this long, and an unbounded one is a slow page. */
    public const PAGE_SIZE = 50;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Record one notification, best effort.
     *
     * Deliberately swallows failures. This is a receipt for something that has
     * already happened; a missing table on a site that has not run migration
     * 0020 yet, or a locked table on a busy host, must not turn a working
     * announcement into a failed one. The alternative — letting this throw —
     * would mean the cron job reports failures for notifications that were
     * genuinely delivered.
     */
    public function record(
        string $email,
        string $channel,
        string $title,
        string $url = '',
        ?int $videoId = null,
    ): void {
        try {
            $this->db->insert('notifications', [
                'recipient_email' => Str::normalizeEmail($email),
                'channel'         => $channel === self::PUSH ? self::PUSH : self::EMAIL,
                'title'           => mb_substr(trim($title), 0, 300),
                'url'             => mb_substr($url, 0, 500),
                'video_id'        => $videoId,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('Could not record a notification: ' . $e->getMessage());
        }
    }

    /**
     * One person's notifications, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forEmail(string $email, int $limit = self::PAGE_SIZE): array
    {
        return $this->db->all(
            'SELECT * FROM {notifications}
              WHERE recipient_email = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ' . max(1, min(200, $limit)),
            [Str::normalizeEmail($email)]
        );
    }

    public function unreadCount(string $email): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {notifications} WHERE recipient_email = ? AND read_at IS NULL',
            [Str::normalizeEmail($email)]
        );
    }

    /**
     * Mark one as read.
     *
     * The email is part of the WHERE, not just the id. An id alone would let
     * anybody mark — and, through delete() below, destroy — somebody else's
     * row by guessing a number, and the ids are sequential.
     */
    public function markRead(int $id, string $email): void
    {
        $this->db->execute(
            'UPDATE {notifications} SET read_at = NOW()
              WHERE id = ? AND recipient_email = ? AND read_at IS NULL',
            [$id, Str::normalizeEmail($email)]
        );
    }

    public function markAllRead(string $email): int
    {
        return $this->db->execute(
            'UPDATE {notifications} SET read_at = NOW() WHERE recipient_email = ? AND read_at IS NULL',
            [Str::normalizeEmail($email)]
        );
    }

    /** Ownership is in the WHERE for the same reason as markRead(). */
    public function delete(int $id, string $email): void
    {
        $this->db->execute(
            'DELETE FROM {notifications} WHERE id = ? AND recipient_email = ?',
            [$id, Str::normalizeEmail($email)]
        );
    }

    public function clear(string $email): int
    {
        return $this->db->execute(
            'DELETE FROM {notifications} WHERE recipient_email = ?',
            [Str::normalizeEmail($email)]
        );
    }
}
