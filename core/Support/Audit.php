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
     * A page of the log, filtered.
     *
     * `recent()` answers the dashboard's question — "what just happened" — and
     * was, until this existed, the ONLY reader. Sixteen files write to this
     * table, `view_audit_log` appears on the permissions screen describing
     * itself as "Read the activity log", and holding it got you fifteen rows.
     *
     * The questions a real one has to answer are different: what did this
     * person do, who touched this video, what happened on the day the thing
     * went wrong. All three are filters, and none of them is "the last
     * fifteen".
     *
     * @param array{actor?: string, action?: string, target?: string, from?: string, to?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int, pages: int, actions: list<string>}
     */
    public static function page(Db $db, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $empty = ['items' => [], 'total' => 0, 'pages' => 1, 'actions' => []];

        try {
            $where = ['1=1'];
            $args = [];

            if (trim($filters['actor'] ?? '') !== '') {
                $where[] = 'actor_email LIKE ?';
                $args[] = '%' . $db->escapeLike(trim($filters['actor'])) . '%';
            }

            // Exact, because the action list is a closed vocabulary offered as
            // a dropdown — a LIKE here would make "video.delete" also match
            // nothing anybody meant.
            if (trim($filters['action'] ?? '') !== '') {
                $where[] = 'action = ?';
                $args[] = trim($filters['action']);
            }

            if (trim($filters['target'] ?? '') !== '') {
                $where[] = '(target_type LIKE ? OR target_id LIKE ? OR detail LIKE ?)';
                $like = '%' . $db->escapeLike(trim($filters['target'])) . '%';
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
            }

            /*
             * Dates are half-open on the upper end: "to" means the END of that
             * day, not midnight at its start. Somebody searching a single day
             * types the same date twice, and a naive `<= '2026-09-03'` returns
             * nothing at all — which reads as "nothing happened" rather than
             * as an off-by-one.
             */
            if (trim($filters['from'] ?? '') !== '') {
                $where[] = 'created_at >= ?';
                $args[] = trim($filters['from']) . ' 00:00:00';
            }

            if (trim($filters['to'] ?? '') !== '') {
                $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
                $args[] = trim($filters['to']) . ' 00:00:00';
            }

            $sql = implode(' AND ', $where);
            $total = (int) $db->value("SELECT COUNT(*) FROM {audit_log} WHERE {$sql}", $args);

            $perPage = max(10, min(500, $perPage));
            $page = max(1, $page);
            $offset = ($page - 1) * $perPage;

            $items = $db->all(
                "SELECT * FROM {audit_log} WHERE {$sql}
                  ORDER BY created_at DESC, id DESC
                  LIMIT {$perPage} OFFSET {$offset}",
                $args
            );

            return [
                'items' => $items,
                'total' => $total,
                'pages' => (int) max(1, (int) ceil($total / $perPage)),
                'actions' => self::actions($db),
            ];
        } catch (Throwable $e) {
            error_log('Portal: could not read the activity log: ' . $e->getMessage());

            return $empty;
        }
    }

    /**
     * Every action verb the log actually holds.
     *
     * Read from the data rather than kept as a list in code. A hardcoded
     * vocabulary goes stale the first time somebody adds an audited action and
     * forgets, and then the filter silently cannot find the very entries most
     * likely to be searched for — the new ones.
     *
     * @return list<string>
     */
    public static function actions(Db $db): array
    {
        try {
            return array_map(
                static fn (array $row): string => (string) $row['action'],
                $db->all('SELECT DISTINCT action FROM {audit_log} ORDER BY action')
            );
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
