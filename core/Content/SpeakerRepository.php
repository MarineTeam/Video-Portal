<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * The speaker directory.
 *
 * Small and flat by design. The interesting decision is what happens on delete:
 * the videos keep existing and lose their attribution, because the alternative
 * — refusing to delete a speaker who has videos — leaves an admin permanently
 * unable to remove a duplicate they created by a typo.
 */
final class SpeakerRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?Speaker
    {
        $row = $this->db->first('SELECT * FROM {speakers} WHERE id = ?', [$id]);
        return $row === null ? null : Speaker::fromRow($row);
    }

    public function findBySlug(string $slug): ?Speaker
    {
        $row = $this->db->first('SELECT * FROM {speakers} WHERE slug = ?', [$slug]);
        return $row === null ? null : Speaker::fromRow($row);
    }

    public function findByAlias(string $slug): ?Speaker
    {
        $id = $this->db->value(
            'SELECT target_id FROM {slug_aliases} WHERE target_type = "speaker" AND slug = ?',
            [$slug]
        );

        return $id === null ? null : $this->find((int) $id);
    }

    /**
     * Everyone, with a count of their videos.
     *
     * @return list<Speaker>
     */
    public function all(): array
    {
        $rows = $this->db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM {videos} v
                      WHERE v.speaker_id = s.id AND v.deleted_at IS NULL) AS video_count
               FROM {speakers} s
              ORDER BY s.name'
        );

        return array_map(static fn (array $row): Speaker => Speaker::fromRow($row), $rows);
    }

    // ----------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Speaker
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw HttpException::badRequest('A speaker needs a name.');
        }

        $id = $this->db->insert('speakers', [
            'slug'       => $this->uniqueSlug((string) ($attributes['slug'] ?? $name)),
            'name'       => $name,
            'bio'        => $attributes['bio'] ?? null,
            'image_url'  => $attributes['image_url'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $speaker = $this->find($id);
        if ($speaker === null) {
            throw new \RuntimeException('The speaker vanished immediately after being created.');
        }

        return $speaker;
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Speaker
    {
        $speaker = $this->find($id);
        if ($speaker === null) {
            throw HttpException::notFound('That speaker does not exist.');
        }

        $fields = [];

        if (isset($attributes['name'])) {
            $name = trim((string) $attributes['name']);
            if ($name === '') {
                throw HttpException::badRequest('A speaker needs a name.');
            }
            $fields['name'] = $name;
        }

        if (isset($attributes['slug'])) {
            $slug = $this->uniqueSlug((string) $attributes['slug'], $id);
            if ($slug !== $speaker->slug) {
                $this->recordAlias($id, $speaker->slug);
                $fields['slug'] = $slug;
            }
        }

        foreach (['bio', 'image_url'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = $attributes[$key];
            }
        }

        if ($fields === []) {
            return $speaker;
        }

        $this->db->update('speakers', $fields, ['id' => $id]);

        return $this->find($id) ?? $speaker;
    }

    /**
     * Remove a speaker; their videos survive, unattributed.
     *
     * The foreign key already sets speaker_id to NULL. Refusing to delete
     * anyone who has videos would sound safer and would mean a duplicate
     * created by a typo could never be tidied away.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {speakers} WHERE id = ?', [$id]);
        $this->db->execute(
            'DELETE FROM {slug_aliases} WHERE target_type = "speaker" AND target_id = ?',
            [$id]
        );
    }

    /** How many videos would lose their attribution if this speaker went. */
    public function videoCount(int $id): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {videos} WHERE speaker_id = ? AND deleted_at IS NULL',
            [$id]
        );
    }

    // ------------------------------------------------------------- internals

    public function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired);
        if ($base === '') {
            $base = 'speaker';
        }

        $slug = $base;
        $suffix = 1;

        while (true) {
            $sql = 'SELECT id FROM {speakers} WHERE slug = ?';
            $params = [$slug];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            if ($this->db->value($sql, $params) === null) {
                return $slug;
            }

            $suffix++;
            $slug = $base . '-' . $suffix;
        }
    }

    private function recordAlias(int $id, string $oldSlug): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO {slug_aliases} (target_type, target_id, slug, created_at)
             VALUES ("speaker", ?, ?, NOW())',
            [$id, $oldSlug]
        );
    }
}
