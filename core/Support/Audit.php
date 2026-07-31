<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Db;
use Throwable;

/**
 * The activity log.
 *
 * Fails silently and deliberately. An audit log that can prevent the action it
 * is recording turns a full disk into a site outage — and the action has
 * usually already happened by the time we get here, so throwing would report a
 * failure that did not occur.
 */
final class Audit
{
    public static function log(
        Db $db,
        ?string $actorEmail,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $detail = null,
        ?string $ip = null
    ): void {
        try {
            $db->execute(
                'INSERT INTO {audit_log}
                    (actor_email, action, target_type, target_id, detail, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $actorEmail,
                    mb_substr($action, 0, 64),
                    $targetType === null ? null : mb_substr($targetType, 0, 32),
                    $targetId === null ? null : mb_substr($targetId, 0, 64),
                    $detail === null ? null : mb_substr($detail, 0, 500),
                    $ip,
                ]
            );
        } catch (Throwable $e) {
            error_log('Portal: could not write to the activity log: ' . $e->getMessage());
        }
    }

    /**
     * Recent entries for the admin screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(Db $db, int $limit = 100): array
    {
        try {
            $limit = max(1, min(500, $limit));
            return $db->all("SELECT * FROM {audit_log} ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Trim the log.
     *
     * Runs from cron. Without it the table grows without bound on a busy site,
     * and on shared hosting the disk quota is real.
     */
    public static function prune(Db $db, int $keepDays = 180): int
    {
        try {
            return $db->execute(
                'DELETE FROM {audit_log} WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [max(7, $keepDays)]
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
