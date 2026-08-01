<?php

declare(strict_types=1);

namespace Portal\Sharing;

use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * A video's standing invite list.
 *
 * "These people may watch this video" — persistent, editable, and separate
 * from any ad-hoc share of the same video to the same person.
 *
 * WHY THIS IS ITS OWN INDEX rather than a query over active shares:
 *
 * The list has to answer "who is on it" independently of whether their link
 * currently works. Deriving membership from live shares would mean someone
 * whose link expired silently drops off the list, and re-adding them looks
 * like a new decision rather than a renewal. Worse, removing someone from the
 * list would have to guess which of their shares the list created, and would
 * inevitably revoke a personal one-off share to the same video.
 *
 * So membership is a row here, and the share it produced is recorded alongside
 * it. Remove revokes exactly the share this list made and nothing else. An
 * ordinary share to the same person for the same video is invisible to the
 * list and untouched by it — a deliberate, documented trade: the same person
 * can end up holding two links to one video, and that is better than a remove
 * that silently cancels access somebody else granted.
 */
final class PrivateList
{
    /** Enough for a class or a team; not a mailing list. */
    public const MAX_EMAILS = 50;

    public function __construct(
        private readonly Db $db,
        private readonly ShareRepository $shares,
    ) {
    }

    /**
     * Who is on the list, and whether their link still works.
     *
     * The two are reported separately on purpose: "on the list but expired" is
     * a real state an admin needs to see and act on, not something to hide.
     *
     * @return list<array{email: string, shareId: ?string, share: ?Share, addedAt: string, addedBy: ?string}>
     */
    public function members(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {private_list_entries} WHERE video_id = ? ORDER BY email',
            [$videoId]
        );

        $shareIds = array_values(array_filter(
            array_map(static fn (array $r): ?string => $r['share_id'] === null ? null : (string) $r['share_id'], $rows)
        ));

        $shares = $shareIds === [] ? [] : $this->shares->findMany($shareIds);

        $members = [];
        foreach ($rows as $row) {
            $shareId = $row['share_id'] === null ? null : (string) $row['share_id'];

            $members[] = [
                'email'   => (string) $row['email'],
                'shareId' => $shareId,
                'share'   => $shareId === null ? null : ($shares[$shareId] ?? null),
                'addedAt' => (string) $row['added_at'],
                'addedBy' => $row['added_by'] === null ? null : (string) $row['added_by'],
            ];
        }

        return $members;
    }

    public function has(int $videoId, string $email): bool
    {
        return $this->db->value(
            'SELECT 1 FROM {private_list_entries} WHERE video_id = ? AND email = ?',
            [$videoId, Str::normalizeEmail($email)]
        ) !== null;
    }

    /**
     * Add people to a video's list.
     *
     * Idempotent. Someone already on the list is skipped entirely — no second
     * link, no second email. Adding a name twice is a slip, not a request for
     * duplicate access, and treating it as one would send a confusing second
     * invitation.
     *
     * @param list<string>         $emails
     * @param array<string, mixed> $options hours, accessMode, watermark, addedBy
     * @return array{added: list<Share>, skipped: list<string>, failed: array<string, string>}
     */
    public function add(int $videoId, array $emails, array $options = []): array
    {
        $added = [];
        $skipped = [];
        $failed = [];

        $seen = [];
        foreach ($emails as $raw) {
            $email = Str::normalizeEmail((string) $raw);

            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            if (!Str::isEmail($email)) {
                $failed[$email] = 'Not a valid email address.';
                continue;
            }

            if (count($added) + count($skipped) >= self::MAX_EMAILS) {
                $failed[$email] = 'The list is full.';
                continue;
            }

            if ($this->has($videoId, $email)) {
                $skipped[] = $email;
                continue;
            }

            try {
                $share = $this->shares->create($videoId, $email, $options + ['viaPrivateList' => true]);

                // The UNIQUE on (video_id, email) is what actually prevents a
                // duplicate; has() above is a courtesy that avoids minting a
                // share we would then have to discard.
                $this->db->execute(
                    'INSERT INTO {private_list_entries} (video_id, email, share_id, added_at, added_by)
                     VALUES (?, ?, ?, NOW(), ?)',
                    [$videoId, $email, $share->id, $options['createdBy'] ?? null]
                );

                $added[] = $share;
            } catch (Throwable $e) {
                $failed[$email] = $e->getMessage();
            }
        }

        return ['added' => $added, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Take someone off the list.
     *
     * Revokes exactly the share this list created for them, and nothing else.
     * If the same person also holds an ordinary share to this video, it keeps
     * working — the list did not create it and has no business cancelling it.
     */
    public function remove(int $videoId, string $email): bool
    {
        $email = Str::normalizeEmail($email);

        $shareId = $this->db->value(
            'SELECT share_id FROM {private_list_entries} WHERE video_id = ? AND email = ?',
            [$videoId, $email]
        );

        $removed = $this->db->execute(
            'DELETE FROM {private_list_entries} WHERE video_id = ? AND email = ?',
            [$videoId, $email]
        ) > 0;

        if ($removed && is_string($shareId) && $shareId !== '') {
            $this->shares->revoke($shareId);
        }

        return $removed;
    }

    /**
     * Which videos is this person on the list for?
     *
     * @return list<int>
     */
    public function videosFor(string $email): array
    {
        return array_map('intval', $this->db->column(
            'SELECT video_id FROM {private_list_entries} WHERE email = ?',
            [Str::normalizeEmail($email)]
        ));
    }

    public function count(int $videoId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {private_list_entries} WHERE video_id = ?',
            [$videoId]
        );
    }
}
