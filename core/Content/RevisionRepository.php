<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * What something looked like before somebody changed it.
 *
 * The snapshot is taken before the write, not after, so the newest revision is
 * the state you can go back TO rather than the state you are already in. That
 * ordering is the whole usability of the feature: an editor who has just
 * destroyed a description wants the top of the list to be the version they
 * destroyed.
 *
 * Only human edits are recorded. The provider sync rewrites titles on a
 * schedule, and burying one editorial change under a hundred machine writes
 * would make the history useless for the thing it exists to do.
 */
final class RevisionRepository
{
    public const VIDEO    = 'video';
    public const CATEGORY = 'category';
    public const SERIES   = 'series';
    public const PLAYLIST = 'playlist';

    /**
     * How many to keep per subject.
     *
     * Enough to undo a bad afternoon, few enough that the table does not grow
     * without limit on a site that has been edited for years. Anything older is
     * dropped as a new one is recorded, so no cleanup job has to exist.
     */
    public const KEEP = 20;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The fields worth remembering, per kind.
     *
     * Explicit rather than "everything on the row": ids, timestamps, and
     * provider bookkeeping are not things anybody edits, and restoring them
     * would be actively wrong — putting back a stale updated_at, or a
     * provider_id that has since been re-synced.
     *
     * @return array<string, list<string>>
     */
    public static function fields(): array
    {
        return [
            self::VIDEO => [
                'title', 'description', 'slug', 'speaker_id', 'series_id',
                'is_published', 'member_only', 'hidden', 'featured', 'pinned',
                'premiere', 'published_at', 'unpublish_at', 'recorded_at',
                'watermark_mode', 'thumbnail_mode',
            ],
            self::CATEGORY => [
                'name', 'slug', 'description', 'parent_id',
                'is_published', 'member_only', 'hidden', 'thumbnail_mode',
            ],
            self::SERIES => [
                'title', 'slug', 'description', 'category_id',
                'is_published', 'member_only', 'hidden', 'featured',
            ],
            self::PLAYLIST => [
                'title', 'slug', 'description',
                'is_published', 'member_only', 'hidden', 'featured',
            ],
        ];
    }

    /** @return array<string, string> the tables each kind lives in */
    private static function tables(): array
    {
        return [
            self::VIDEO    => 'videos',
            self::CATEGORY => 'categories',
            self::SERIES   => 'series',
            self::PLAYLIST => 'playlists',
        ];
    }

    // ----------------------------------------------------------------- writes

    /**
     * Snapshot something as it is now.
     *
     * Call BEFORE applying an edit. Returns the new revision's id, or null when
     * there was nothing to snapshot — a subject that does not exist, or an
     * unrecognised kind.
     */
    public function record(string $subjectType, int $subjectId, string $changedBy = ''): ?int
    {
        $fields = self::fields()[$subjectType] ?? null;
        $table = self::tables()[$subjectType] ?? null;

        if ($fields === null || $table === null) {
            return null;
        }

        $row = $this->db->first(
            'SELECT ' . implode(', ', $fields) . " FROM {{$table}} WHERE id = ?",
            [$subjectId]
        );

        if ($row === null) {
            return null;
        }

        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // A description containing invalid UTF-8 would otherwise store the
            // string "false" and restore garbage. Losing the revision is the
            // better failure.
            return null;
        }

        /*
         * Skip a snapshot identical to the newest one.
         *
         * Saving a form without changing anything is common — an editor opens
         * the page, thinks better of it, presses Save. Recording that pushes a
         * real earlier version off the end of the list.
         */
        $newest = $this->db->value(
            'SELECT data FROM {revisions}
              WHERE subject_type = ? AND subject_id = ?
              ORDER BY id DESC LIMIT 1',
            [$subjectType, $subjectId]
        );

        if (is_string($newest) && $newest === $encoded) {
            return null;
        }

        $id = $this->db->insert('revisions', [
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'data'         => $encoded,
            'changed_by'   => substr($changedBy, 0, 254),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->prune($subjectType, $subjectId);

        return $id;
    }

    /**
     * Drop everything past the keep limit.
     *
     * Done here rather than in a cleanup job, so the bound holds on a site
     * whose cron never runs — which is a site this product has to work on.
     */
    public function prune(string $subjectType, int $subjectId): int
    {
        $keepFrom = $this->db->value(
            'SELECT id FROM {revisions}
              WHERE subject_type = ? AND subject_id = ?
              ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP,
            [$subjectType, $subjectId]
        );

        if ($keepFrom === null) {
            return 0;
        }

        return $this->db->execute(
            'DELETE FROM {revisions} WHERE subject_type = ? AND subject_id = ? AND id <= ?',
            [$subjectType, $subjectId, (int) $keepFrom]
        );
    }

    /** Remove revisions whose subject no longer exists. */
    public function pruneOrphans(): int
    {
        $removed = 0;

        foreach (self::tables() as $type => $table) {
            $removed += $this->db->execute(
                "DELETE r FROM {revisions} r
                  LEFT JOIN {{$table}} t ON t.id = r.subject_id
                  WHERE r.subject_type = ? AND t.id IS NULL",
                [$type]
            );
        }

        return $removed;
    }

    // ------------------------------------------------------------------ reads

    /**
     * The history of one thing, newest first.
     *
     * @return list<array{id: int, changedBy: string, createdAt: string, data: array<string, mixed>}>
     */
    public function forSubject(string $subjectType, int $subjectId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {revisions}
              WHERE subject_type = ? AND subject_id = ?
              ORDER BY id DESC',
            [$subjectType, $subjectId]
        );

        return array_map(static fn (array $row): array => [
            'id'        => (int) $row['id'],
            'changedBy' => (string) $row['changed_by'],
            'createdAt' => (string) $row['created_at'],
            'data'      => self::decode((string) $row['data']),
        ], $rows);
    }

    /** @return array{id: int, subjectType: string, subjectId: int, data: array<string, mixed>}|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM {revisions} WHERE id = ?', [$id]);

        if ($row === null) {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'subjectType' => (string) $row['subject_type'],
            'subjectId'   => (int) $row['subject_id'],
            'data'        => self::decode((string) $row['data']),
        ];
    }

    /**
     * What this revision would change if restored.
     *
     * Compared against the subject as it is NOW, not against the revision
     * before it — the question an editor is asking is "what do I get back",
     * and answering a different question would be worse than answering none.
     *
     * @param  array<string, mixed> $data
     * @return array<string, array{from: string, to: string}>
     */
    public function differences(string $subjectType, int $subjectId, array $data): array
    {
        $fields = self::fields()[$subjectType] ?? null;
        $table = self::tables()[$subjectType] ?? null;

        if ($fields === null || $table === null) {
            return [];
        }

        $current = $this->db->first(
            'SELECT ' . implode(', ', $fields) . " FROM {{$table}} WHERE id = ?",
            [$subjectId]
        );

        if ($current === null) {
            return [];
        }

        $out = [];

        foreach ($fields as $field) {
            $now = self::describe($current[$field] ?? null);
            $then = self::describe($data[$field] ?? null);

            if ($now !== $then) {
                $out[$field] = ['from' => $now, 'to' => $then];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A value as a short string, for comparison and for display.
     *
     * Every value reaching here came from the database or from JSON encoded out
     * of it, so it is a string, an int, or null — the connection has
     * STRINGIFY_FETCHES off, which is why an int is possible at all. Rendering
     * both sides the same way before comparing is what stops a TINYINT read as
     * int 1 and the same field decoded from JSON differing by type rather than
     * by value.
     *
     * Null and empty are the same answer on purpose. A description cleared to
     * "" and one that was never set are not a change anybody wants reported.
     */
    private static function describe(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }
}
