<?php

declare(strict_types=1);

namespace Portal\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Content\VideoRepository;
use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;
use Throwable;

/**
 * Creating, finding, and changing share links.
 *
 * Bulk operations are first-class rather than a loop bolted on afterwards:
 * sharing a collection with a class list is the normal case, not the exotic
 * one, and each item succeeds or fails independently so a single bad address
 * cannot sink the batch.
 */
final class ShareRepository
{
    /** Caps on one bulk operation. Generous, but not unbounded. */
    public const MAX_VIDEOS     = 50;
    public const MAX_RECIPIENTS = 50;
    public const MAX_PAIRS      = 300;

    /** Cap on ids accepted by a bulk lifecycle action. */
    public const MAX_BULK_IDS = 100;

    public function __construct(
        private readonly Db $db,
        private readonly VideoRepository $videos,
    ) {
    }

    // ------------------------------------------------------------------ reads

    public function find(string $id): ?Share
    {
        // Validate the shape before querying. A malformed id is far more
        // likely to be someone probing than a typo.
        if (!Share::isValidId($id)) {
            return null;
        }

        $row = $this->db->first('SELECT * FROM {shares} WHERE id = ?', [$id]);

        return $row === null ? null : Share::fromRow($row);
    }

    /**
     * @param list<string> $ids
     * @return array<string, Share> keyed by id
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_filter(
            array_unique($ids),
            static fn (string $id): bool => Share::isValidId($id)
        ));

        if ($ids === []) {
            return [];
        }

        $ids = array_slice($ids, 0, self::MAX_BULK_IDS);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $shares = [];
        foreach ($this->db->all("SELECT * FROM {shares} WHERE id IN ({$placeholders})", $ids) as $row) {
            $share = Share::fromRow($row);
            $shares[$share->id] = $share;
        }

        return $shares;
    }

    /**
     * Every share for one recipient that is still usable.
     *
     * @return list<Share>
     */
    public function liveForRecipient(string $email): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {shares}
              WHERE recipient_email = ?
                AND revoked_at IS NULL
                AND expires_at > NOW()
              ORDER BY expires_at ASC',
            [Str::normalizeEmail($email)]
        );

        return array_map(static fn (array $row): Share => Share::fromRow($row), $rows);
    }

    /**
     * The admin share table.
     *
     * @param array<string, mixed> $filters videoId, email, status, search
     * @return array{items: list<Share>, total: int}
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $conditions = ['1 = 1'];
        $params = [];

        if (!empty($filters['videoId'])) {
            $conditions[] = 'video_id = ?';
            $params[] = (int) $filters['videoId'];
        }

        if (!empty($filters['email'])) {
            $conditions[] = 'recipient_email = ?';
            $params[] = Str::normalizeEmail((string) $filters['email']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $this->db->escapeLike(trim((string) $filters['search'])) . '%';
            $conditions[] = '(recipient_email LIKE ? OR video_title LIKE ?)';
            $params[] = $term;
            $params[] = $term;
        }

        $conditions[] = match ($filters['status'] ?? 'all') {
            'live'    => 'revoked_at IS NULL AND expires_at > NOW()',
            'expired' => 'revoked_at IS NULL AND expires_at <= NOW()',
            'revoked' => 'revoked_at IS NOT NULL',
            default   => '1 = 1',
        };

        $where = implode(' AND ', $conditions);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {shares} WHERE {$where}", $params);

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->all(
            "SELECT * FROM {shares} WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => array_map(static fn (array $row): Share => Share::fromRow($row), $rows),
            'total' => $total,
        ];
    }

    // --------------------------------------------------------------- creating

    /**
     * Create one share.
     *
     * @param array<string, mixed> $options hours, accessMode, watermark,
     *                                      viaPrivateList, createdBy
     */
    public function create(int $videoId, string $email, array $options = []): Share
    {
        $email = Str::normalizeEmail($email);

        if (!Str::isEmail($email)) {
            throw HttpException::badRequest("'{$email}' is not a valid email address.");
        }

        $video = $this->videos->find($videoId);
        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        $hours = Share::clampHours((int) ($options['hours'] ?? Share::DEFAULT_HOURS));

        $accessMode = (string) ($options['accessMode'] ?? Share::MODE_ACCOUNT);
        if (!in_array($accessMode, [Share::MODE_ACCOUNT, Share::MODE_GATE], true)) {
            $accessMode = Share::MODE_ACCOUNT;
        }

        $watermark = (string) ($options['watermark'] ?? 'default');
        if (!in_array($watermark, ['default', 'on', 'off'], true)) {
            $watermark = 'default';
        }

        /*
         * The passphrase, hashed here and never held anywhere else.
         *
         * An unacceptable one — too short, or only whitespace — hashes to null
         * rather than throwing, which means it creates a link with NO
         * passphrase. That is the safe direction only because the form
         * validates first and refuses; if this were the only check, a typo
         * would silently produce an unprotected link. Stated so the next
         * caller knows the validation is theirs to do.
         */
        $passwordHash = SharePassword::hash(
            isset($options['passphrase']) && is_string($options['passphrase']) && $options['passphrase'] !== ''
                ? $options['passphrase']
                : null
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify("+{$hours} hours");

        $id = Share::newId();

        $this->db->insert('shares', [
            'id'               => $id,
            'video_id'         => $video->id,
            // Denormalized so the share table renders without joining videos,
            // and so a share's own history survives the video being deleted.
            'video_title'      => mb_substr($video->title, 0, 200),
            'recipient_email'  => $email,
            'access_mode'      => $accessMode,
            'password_hash'    => $passwordHash,
            'created_at'       => $now->format('Y-m-d H:i:s'),
            'expires_at'       => $expires->format('Y-m-d H:i:s'),
            'watermark_mode'   => $watermark,
            'via_private_list' => !empty($options['viaPrivateList']) ? 1 : 0,
            'created_by'       => $options['createdBy'] ?? null,
        ]);

        $share = $this->find($id);
        if ($share === null) {
            throw new \RuntimeException('The share was created but could not be read back.');
        }

        do_action('share_created', $share);

        return $share;
    }

    /**
     * Create the cross product of videos and recipients.
     *
     * One independently revocable link per pair — never one link shared by
     * several people. That is the whole point: revoking one person's access
     * must not disturb anyone else's, and per-recipient tracking is only
     * meaningful if the links are distinct.
     *
     * @param list<int>            $videoIds
     * @param list<string>         $emails
     * @param array<string, mixed> $options
     * @return array{created: list<Share>, failed: array<string, string>}
     */
    public function createBulk(array $videoIds, array $emails, array $options = []): array
    {
        $videoIds = array_slice(array_values(array_unique(array_map('intval', $videoIds))), 0, self::MAX_VIDEOS);

        $normalized = [];
        $failed = [];

        foreach ($emails as $email) {
            $email = Str::normalizeEmail((string) $email);
            if ($email === '') {
                continue;
            }
            if (!Str::isEmail($email)) {
                $failed[$email] = 'Not a valid email address.';
                continue;
            }
            $normalized[$email] = true;
        }

        $emails = array_slice(array_keys($normalized), 0, self::MAX_RECIPIENTS);

        if ($videoIds === [] || $emails === []) {
            return ['created' => [], 'failed' => $failed];
        }

        if (count($videoIds) * count($emails) > self::MAX_PAIRS) {
            throw HttpException::badRequest(sprintf(
                'That would create %d links. The limit is %d per action — share fewer videos or fewer people at once.',
                count($videoIds) * count($emails),
                self::MAX_PAIRS
            ));
        }

        $created = [];

        foreach ($videoIds as $videoId) {
            foreach ($emails as $email) {
                try {
                    $created[] = $this->create($videoId, $email, $options);
                } catch (Throwable $e) {
                    // Independent: one bad pair must not sink the batch.
                    $failed["{$videoId}:{$email}"] = $e->getMessage();
                }
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    // -------------------------------------------------------------- lifecycle

    /**
     * Revoke a link.
     *
     * Idempotent: revoking an already-revoked share reports success, because
     * the caller's intent — that this link stop working — is satisfied. The
     * previous expiry is remembered so Restore can put it back rather than
     * inventing a new one.
     */
    public function revoke(string $id): bool
    {
        $share = $this->find($id);
        if ($share === null) {
            return false;
        }
        if ($share->isRevoked()) {
            return true;
        }

        $this->db->execute(
            'UPDATE {shares}
                SET revoked_at = NOW(), previous_expires_at = expires_at
              WHERE id = ? AND revoked_at IS NULL',
            [$id]
        );

        do_action('share_revoked', $share);

        return true;
    }

    /**
     * Undo a revoke.
     *
     * Restores the original expiry, which may already be in the past — that is
     * correct, and the admin can then Extend it. Silently granting fresh time
     * would be a different decision than the one they made.
     *
     * @return array{ok: bool, reason: string}
     */
    public function restore(string $id): array
    {
        $share = $this->find($id);

        if ($share === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if (!$share->isRevoked()) {
            return ['ok' => false, 'reason' => 'not_revoked'];
        }

        $this->db->execute(
            'UPDATE {shares}
                SET revoked_at = NULL,
                    expires_at = COALESCE(previous_expires_at, expires_at),
                    previous_expires_at = NULL
              WHERE id = ?',
            [$id]
        );

        do_action('share_restored', $share);

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Extend a link's life.
     *
     * Measured from NOW, not from the old expiry, so extending a link that
     * lapsed last week gives the full requested window rather than a period
     * that already elapsed.
     *
     * Refuses a revoked share. Allowing it would make Extend a silent
     * un-revoke, and un-revoking should be a decision someone takes on purpose.
     *
     * @return array{ok: bool, reason: string}
     */
    public function extend(string $id, int $hours): array
    {
        $share = $this->find($id);

        if ($share === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($share->isRevoked()) {
            return ['ok' => false, 'reason' => 'revoked'];
        }

        $hours = Share::clampHours($hours);

        $this->db->execute(
            'UPDATE {shares} SET expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?',
            [$hours, $id]
        );

        do_action('share_extended', $share, $hours);

        return ['ok' => true, 'reason' => ''];
    }

    /** Irreversible. Separate from revoke on purpose. */
    public function deletePermanently(string $id): bool
    {
        if (!Share::isValidId($id)) {
            return false;
        }

        return $this->db->execute('DELETE FROM {shares} WHERE id = ?', [$id]) > 0;
    }

    /**
     * Apply a lifecycle action to many shares, reporting each independently.
     *
     * @param list<string> $ids
     * @return array{ok: list<string>, failed: array<string, string>}
     */
    public function bulk(string $action, array $ids, int $hours = Share::DEFAULT_HOURS): array
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, self::MAX_BULK_IDS);

        $ok = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $result = match ($action) {
                    'revoke'  => ['ok' => $this->revoke($id), 'reason' => 'not_found'],
                    'restore' => $this->restore($id),
                    'extend'  => $this->extend($id, $hours),
                    'delete'  => ['ok' => $this->deletePermanently($id), 'reason' => 'not_found'],
                    default   => ['ok' => false, 'reason' => 'unknown_action'],
                };

                if ($result['ok']) {
                    $ok[] = $id;
                } else {
                    $failed[$id] = $this->explain($result['reason']);
                }
            } catch (Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    private function explain(string $reason): string
    {
        return match ($reason) {
            'not_found'      => 'No such link.',
            'not_revoked'    => 'That link is not revoked.',
            'revoked'        => 'That link is revoked. Restore it first.',
            'unknown_action' => 'Unknown action.',
            default          => $reason,
        };
    }

    // --------------------------------------------------------------- tracking

    /**
     * Record that the page was opened.
     *
     * Every open counts, including repeats, because "opened four times" is
     * genuinely useful to whoever sent it. Deliberately does not touch
     * expires_at: viewing a link must never extend it.
     */
    public function recordView(string $id): void
    {
        try {
            $this->db->execute(
                'UPDATE {shares}
                    SET view_count = view_count + 1,
                        first_viewed_at = COALESCE(first_viewed_at, NOW()),
                        last_viewed_at = NOW()
                  WHERE id = ?',
                [$id]
            );
        } catch (Throwable $e) {
            // Tracking must never block playback.
            error_log('Portal: could not record a share view: ' . $e->getMessage());
        }

        // Named in the Phase-1 plan and never fired until something needed it.
        // After the write and outside the try, so a listener cannot prevent
        // the count being recorded and a failed count still reports the view.
        do_action('share_viewed', $id);
    }

    /**
     * Record real playback, as opposed to merely opening the page.
     *
     * furthest_percent is a high-water mark: seeking backwards must not lower
     * it, or "how much of this did they actually watch" becomes meaningless.
     */
    public function recordPlayback(string $id, string $event, int $percent = 0): void
    {
        $percent = max(0, min(100, $percent));

        try {
            match ($event) {
                'play' => $this->db->execute(
                    'UPDATE {shares} SET play_count = play_count + 1 WHERE id = ?',
                    [$id]
                ),

                'progress' => $this->db->execute(
                    'UPDATE {shares} SET furthest_percent = GREATEST(furthest_percent, ?) WHERE id = ?',
                    [$percent, $id]
                ),

                'ended' => $this->db->execute(
                    'UPDATE {shares}
                        SET furthest_percent = 100,
                            completed_at = COALESCE(completed_at, NOW())
                      WHERE id = ?',
                    [$id]
                ),

                default => null,
            };
        } catch (Throwable $e) {
            error_log('Portal: could not record share playback: ' . $e->getMessage());
        }
    }

    public function markEmailed(string $id, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE {shares} SET emailed_at = ?, email_error = ? WHERE id = ?',
            [$error === null ? date('Y-m-d H:i:s') : null, $error === null ? null : mb_substr($error, 0, 500), $id]
        );
    }

    // ---------------------------------------------------------------- cleanup

    /**
     * Remove rows past the grace period.
     *
     * The only thing that deletes shares on a schedule, and it deliberately
     * waits sixty days after expiry or revocation so Extend and Restore keep
     * working long after a link stops being usable.
     */
    /**
     * @param int|null $limit most rows to remove in one call, or null for all
     */
    public function purgeExpired(?int $limit = null): int
    {
        /*
         * Bounded when the caller asks, because the scheduled job now runs
         * here — and on a host without real cron that means it runs inside
         * somebody's ordinary page view. A site that has never been cleaned
         * could have years of lapsed links, and one enormous DELETE would be a
         * visitor waiting for it. The same reasoning bounds videos.sync to one
         * page of a hundred.
         *
         * Oldest first, so a bounded run drains the backlog in order rather
         * than skimming whatever the database happened to reach first, and
         * repeated runs make progress instead of revisiting the same rows.
         *
         * The admin button passes nothing and clears the lot: somebody who
         * pressed it is waiting for it deliberately.
         */
        $bound = '';
        if ($limit !== null && $limit > 0) {
            $bound = ' ORDER BY COALESCE(revoked_at, expires_at) ASC LIMIT ' . $limit;
        }

        try {
            return $this->db->execute(
                'DELETE FROM {shares}
                  WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL ? DAY))
                     OR (revoked_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY))'
                . $bound,
                [Share::GRACE_DAYS, Share::GRACE_DAYS]
            );
        } catch (Throwable $e) {
            error_log('Portal: share cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** How many rows the cleanup would remove, for the admin button. */
    public function purgeableCount(): int
    {
        try {
            return (int) $this->db->value(
                'SELECT COUNT(*) FROM {shares}
                  WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL ? DAY))
                     OR (revoked_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY))',
                [Share::GRACE_DAYS, Share::GRACE_DAYS]
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
