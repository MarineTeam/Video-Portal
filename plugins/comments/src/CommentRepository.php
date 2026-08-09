<?php

declare(strict_types=1);

namespace Portal\Plugins\Comments;

use Portal\Db;
use Portal\Support\Str;

/**
 * Reading and writing comments.
 *
 * The thread shape is fixed at one level: a comment either is a reply or is
 * not. Everything here assumes that, and the schema enforces it, so nothing has
 * to defend against a reply to a reply.
 */
final class CommentRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /**
     * The thread for one video, ready to render.
     *
     * Two queries regardless of how many comments there are: one for the rows,
     * one for the reply counts. The obvious version — fetch the top level, then
     * fetch replies per comment — is a query per comment, which is invisible on
     * a video with three and painful on the one that got popular.
     *
     * @return list<array<string, mixed>> top-level comments, each with 'replies'
     */
    /** Top-level comments per page. Replies always travel with their parent. */
    public const PER_PAGE = 20;

    /**
     * One page of the thread.
     *
     * Paginated on TOP-LEVEL comments only, and replies come with whichever
     * parent they belong to however many there are. A page boundary drawn
     * through a conversation would put answers on one page and the question on
     * another, which is not a shorter page — it is an unreadable one.
     *
     * The first query asks only for the ids on this page, so a video with two
     * thousand comments loads twenty parents and their replies rather than all
     * two thousand rows. That was the whole problem: the original version
     * selected every comment on the video and filtered in PHP, which is fine
     * for the first year and is a memory limit afterwards.
     *
     * @return array{comments: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function page(int $videoId, int $page = 1, bool $includeHidden = false): array
    {
        $total = (int) $this->db->value(
            'SELECT COUNT(*) FROM {comments}
              WHERE video_id = ? AND parent_id IS NULL'
              . ($includeHidden ? '' : ' AND status IN (\'approved\', \'removed\')'),
            [$videoId]
        );

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));

        $parents = $this->db->all(
            'SELECT id FROM {comments}
              WHERE video_id = ? AND parent_id IS NULL'
              . ($includeHidden ? '' : ' AND status IN (\'approved\', \'removed\')') . '
              ORDER BY created_at ASC, id ASC
              LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            [$videoId]
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $parents);

        return [
            'comments' => $ids === [] ? [] : $this->thread($videoId, $includeHidden, $ids),
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
        ];
    }

    /**
     * @param list<int>|null $parentIds when given, only these top-level
     *                                  comments and their replies are loaded
     */
    public function thread(int $videoId, bool $includeHidden = false, ?array $parentIds = null): array
    {
        if ($parentIds === []) {
            return [];
        }

        if ($parentIds === null) {
            $rows = $this->db->all(
                'SELECT * FROM {comments} WHERE video_id = ? ORDER BY created_at ASC',
                [$videoId]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($parentIds), '?'));

            $rows = $this->db->all(
                'SELECT * FROM {comments}
                  WHERE video_id = ?
                    AND (id IN (' . $placeholders . ') OR parent_id IN (' . $placeholders . '))
                  ORDER BY created_at ASC',
                [$videoId, ...$parentIds, ...$parentIds]
            );
        }

        $replyCounts = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null && (string) $row['status'] === CommentPolicy::STATUS_APPROVED) {
                $replyCounts[(int) $row['parent_id']] = ($replyCounts[(int) $row['parent_id']] ?? 0) + 1;
            }
        }

        $top = [];
        $repliesByParent = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];

            if (!$includeHidden && !CommentPolicy::isVisible($status, $replyCounts[$id] ?? 0)) {
                continue;
            }

            $comment = [
                'id'        => $id,
                'parentId'  => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'author'    => (string) $row['author_name'],
                'body'      => (string) $row['body'],
                'status'    => $status,
                'createdAt' => (string) $row['created_at'],
                'removed'   => $status === CommentPolicy::STATUS_REMOVED,
                /*
                 * The author's address travels with the comment so the view can
                 * decide whether to offer Edit — and is never rendered. It is
                 * the one field here that must not reach a page: an address
                 * shown beside a comment is an address a spammer harvests.
                 */
                'authorEmail' => (string) $row['author_email'],
                'edited'    => $row['edited_at'] !== null,
                'replies'   => [],
            ];

            if ($comment['parentId'] === null) {
                $top[$id] = $comment;
            } else {
                $repliesByParent[$comment['parentId']][] = $comment;
            }
        }

        foreach ($repliesByParent as $parentId => $replies) {
            if (isset($top[$parentId])) {
                $top[$parentId]['replies'] = $replies;
            }
        }

        return array_values($top);
    }

    /** How many comments an ordinary reader would see on this video. */
    public function visibleCount(int $videoId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {comments} WHERE video_id = ? AND status = ?',
            [$videoId, CommentPolicy::STATUS_APPROVED]
        );
    }

    /**
     * Visible counts for a whole listing at once.
     *
     * Batched, because the alternative is a query per card — the mistake the
     * batched thumbnail modes exist to avoid, and one that would land on the
     * homepage of every site with this plugin on.
     *
     * @param  list<int> $videoIds
     * @return array<int, int> video id => count, omitting videos with none
     */
    public function countsFor(array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT video_id, COUNT(*) AS total FROM {comments}
              WHERE status = ? AND video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              GROUP BY video_id',
            [CommentPolicy::STATUS_APPROVED, ...$ids]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['video_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How long the moderation queue has been waiting.
     *
     * The oldest pending comment, which is the number that says whether a queue
     * is being worked or merely exists. A count alone does not: three comments
     * posted this morning and three from March look identical.
     *
     * @return array{count: int, oldest: ?string}
     */
    public function queueAge(): array
    {
        $row = $this->db->first(
            'SELECT COUNT(*) AS total, MIN(created_at) AS oldest
               FROM {comments} WHERE status = ?',
            [CommentPolicy::STATUS_PENDING]
        );

        return [
            'count'  => (int) ($row['total'] ?? 0),
            'oldest' => $row['oldest'] ?? null,
        ];
    }

    /**
     * How many approved comments this author already has.
     *
     * The number the newcomer rule turns on. Counted by email rather than by
     * user id so somebody whose account was deleted and recreated is still
     * recognised as established.
     */
    public function approvedCountFor(string $email): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {comments} WHERE author_email = ? AND status = ?',
            [Str::normalizeEmail($email), CommentPolicy::STATUS_APPROVED]
        );
    }

    /**
     * The moderation queue.
     *
     * @return list<array<string, mixed>>
     */
    public function forModeration(string $status = CommentPolicy::STATUS_PENDING, int $limit = 100): array
    {
        $rows = $this->db->all(
            'SELECT c.*, v.title AS video_title, v.slug AS video_slug
               FROM {comments} c
               JOIN {videos} v ON v.id = c.video_id
              WHERE c.status = ?
              ORDER BY c.report_count DESC, c.created_at ASC
              LIMIT ' . max(1, min(500, $limit)),
            [$status]
        );

        return array_map(static fn (array $row): array => $row, $rows);
    }

    /** @return array<string, int> status => how many */
    public function counts(): array
    {
        $out = [
            CommentPolicy::STATUS_PENDING  => 0,
            CommentPolicy::STATUS_APPROVED => 0,
            CommentPolicy::STATUS_SPAM     => 0,
            CommentPolicy::STATUS_REMOVED  => 0,
        ];

        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM {comments} GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    // ----------------------------------------------------------------- writes

    /**
     * Post a comment.
     *
     * @return array{id: int, status: string}
     */
    public function create(
        int $videoId,
        ?int $parentId,
        string $authorName,
        string $authorEmail,
        string $body,
        string $status,
        string $ip
    ): array {
        // A reply to something that is not a top-level comment on this video is
        // either a stale form or someone editing the markup. Flattened to a
        // top-level comment rather than refused: the person wrote something
        // real, and losing it to a race would be the worse outcome.
        if ($parentId !== null && !$this->isTopLevelOn($parentId, $videoId)) {
            $parentId = null;
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('comments', [
            'video_id'     => $videoId,
            'parent_id'    => $parentId,
            'user_id'      => $this->userIdFor($authorEmail),
            'author_name'  => $authorName,
            'author_email' => Str::normalizeEmail($authorEmail),
            'body'         => $body,
            'status'       => $status,
            'ip'           => substr($ip, 0, 45),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        /*
         * Fired whatever the moderation status, including "held".
         *
         * A comment waiting in a queue is precisely what a moderator wants to
         * be told about — announcing only the approved ones would notify on the
         * comments that need no attention and stay silent on the ones that do.
         * The status is in the payload so a listener that only cares about
         * published comments can still tell.
         */
        do_action('comment_posted', $id, $videoId, $status, $authorName);

        return ['id' => $id, 'status' => $status];
    }

    /** @return array<string, mixed>|null */
    public function find(int $commentId): ?array
    {
        return $this->db->first('SELECT * FROM {comments} WHERE id = ?', [$commentId]);
    }

    /**
     * Replace the words of a comment, and re-decide whether it is visible.
     *
     * The status is passed in rather than preserved, because an edit re-runs
     * the same moderation decision a new comment gets — see
     * CommentPolicy::statusAfterEdit. Keeping the old status would let somebody
     * post something harmless, wait for approval, and then edit it into
     * whatever they actually wanted to say.
     */
    public function edit(int $commentId, string $body, string $status): void
    {
        $this->db->execute(
            'UPDATE {comments}
                SET body = ?, status = ?, edited_at = NOW(), updated_at = NOW()
              WHERE id = ?',
            [$body, $status, $commentId]
        );
    }

    public function setStatus(int $commentId, string $status): void
    {
        if (!in_array($status, CommentPolicy::moderatorStatuses(), true)) {
            return;
        }

        $this->db->execute(
            'UPDATE {comments} SET status = ?, updated_at = NOW() WHERE id = ?',
            [$status, $commentId]
        );
    }

    /**
     * Approve everything this author has waiting.
     *
     * The action a moderator actually wants after reading one good comment from
     * a newcomer: the point of holding them was to find out whether they were a
     * person, and that question has now been answered.
     *
     * @return int how many were approved
     */
    public function approveAuthor(string $email): int
    {
        return $this->db->execute(
            'UPDATE {comments} SET status = ?, updated_at = NOW()
              WHERE author_email = ? AND status = ?',
            [CommentPolicy::STATUS_APPROVED, Str::normalizeEmail($email), CommentPolicy::STATUS_PENDING]
        );
    }

    /** Permanently. Replies go with it, by cascade. */
    public function delete(int $commentId): void
    {
        $this->db->execute('DELETE FROM {comments} WHERE id = ?', [$commentId]);
    }

    /**
     * Record a report.
     *
     * The unique index does the deduplicating rather than a read-then-write,
     * which would race between two tabs and let one person report twice.
     *
     * @return bool whether this was a new report
     */
    public function report(int $commentId, string $reporterEmail, string $reason): bool
    {
        $affected = $this->db->execute(
            'INSERT IGNORE INTO {comment_reports} (comment_id, reporter_email, reason, created_at)
             VALUES (?, ?, ?, NOW())',
            [$commentId, Str::normalizeEmail($reporterEmail), substr($reason, 0, 255)]
        );

        if ($affected === 0) {
            return false;
        }

        // Recounted from the table rather than incremented, so the stored
        // number cannot drift away from the rows it claims to summarise.
        $this->db->execute(
            'UPDATE {comments}
                SET report_count = (SELECT COUNT(*) FROM {comment_reports} WHERE comment_id = ?)
              WHERE id = ?',
            [$commentId, $commentId]
        );

        return true;
    }

    // ------------------------------------------------------------- internals

    private function isTopLevelOn(int $commentId, int $videoId): bool
    {
        $row = $this->db->first(
            'SELECT parent_id FROM {comments} WHERE id = ? AND video_id = ?',
            [$commentId, $videoId]
        );

        return $row !== null && $row['parent_id'] === null;
    }

    private function userIdFor(string $email): ?int
    {
        $id = $this->db->value('SELECT id FROM {users} WHERE email = ?', [Str::normalizeEmail($email)]);

        return $id === null ? null : (int) $id;
    }
}
