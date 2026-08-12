<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Series, and the order of the videos inside them.
 *
 * The ordering lives on the video (series_id, series_position) rather than in a
 * join table, because a video belongs to at most one series. A join table would
 * permit "episode 3 of two different series", which is not a thing, and would
 * then need a constraint to forbid what the column already makes impossible.
 */
final class SeriesRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?Series
    {
        $row = $this->db->first('SELECT * FROM {series} WHERE id = ?', [$id]);
        return $row === null ? null : Series::fromRow($row);
    }

    public function findBySlug(string $slug): ?Series
    {
        $row = $this->db->first('SELECT * FROM {series} WHERE slug = ?', [$slug]);
        return $row === null ? null : Series::fromRow($row);
    }

    /**
     * Honour a slug from before a rename.
     *
     * A series address may have been printed in a bulletin or emailed out;
     * fixing a typo in the title should not break it.
     */
    public function findByAlias(string $slug): ?Series
    {
        $id = $this->db->value(
            'SELECT target_id FROM {slug_aliases} WHERE target_type = "series" AND slug = ?',
            [$slug]
        );

        return $id === null ? null : $this->find((int) $id);
    }

    /**
     * Every series, each carrying how many videos it holds.
     *
     * The count is a correlated subquery rather than a join with GROUP BY, so a
     * series with no videos still appears — which is exactly the one an admin
     * is most likely to be looking for, having just created it.
     *
     * @return list<Series>
     */
    public function all(bool $includeUnpublished = false): array
    {
        $where = $includeUnpublished ? '' : ' WHERE s.is_published = 1 AND s.hidden = 0';

        $rows = $this->db->all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {videos} v
                      WHERE v.series_id = s.id AND v.deleted_at IS NULL) AS video_count
               FROM {series} s
               {$where}
              ORDER BY s.position, s.title"
        );

        return array_map(static fn (array $row): Series => Series::fromRow($row), $rows);
    }

    /**
     * Series whose title or description matches every term.
     *
     * Surfaced above the video results, because somebody typing a series name
     * usually wants the series page — the list of its episodes in order — and
     * not twelve of its episodes scattered through a relevance ranking.
     *
     * @return list<Series>
     */
    public function search(string $query, int $limit = 5, bool $includeUnpublished = false): array
    {
        $terms = SearchQuery::terms($query);
        if ($terms === []) {
            return [];
        }

        $conditions = $includeUnpublished ? [] : ['s.is_published = 1', 's.hidden = 0'];
        $params = [];

        foreach ($terms as $term) {
            $like = '%' . $this->db->escapeLike($term) . '%';
            $conditions[] = '(LOWER(s.title) LIKE ? OR LOWER(s.description) LIKE ?)';
            array_push($params, $like, $like);
        }

        $rows = $this->db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM {videos} v
                      WHERE v.series_id = s.id AND v.deleted_at IS NULL) AS video_count
               FROM {series} s
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY s.title
              LIMIT ' . max(1, min(50, $limit)),
            $params
        );

        return array_map(static fn (array $row): Series => Series::fromRow($row), $rows);
    }

    // ----------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Series
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            throw HttpException::badRequest('A series needs a title.');
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('series', [
            'slug'         => $this->uniqueSlug((string) ($attributes['slug'] ?? $title)),
            'title'        => $title,
            'category_id'  => $this->categoryId($attributes['category_id'] ?? null),
            'description'  => $attributes['description'] ?? null,
            'position'     => $this->nextPosition(),
            'is_published' => isset($attributes['is_published']) ? (int) (bool) $attributes['is_published'] : 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $series = $this->find($id);
        if ($series === null) {
            throw new \RuntimeException('The series vanished immediately after being created.');
        }

        return $series;
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Series
    {
        $series = $this->find($id);
        if ($series === null) {
            throw HttpException::notFound('That series does not exist.');
        }

        $fields = [];

        if (isset($attributes['title'])) {
            $title = trim((string) $attributes['title']);
            if ($title === '') {
                throw HttpException::badRequest('A series needs a title.');
            }
            $fields['title'] = $title;
        }

        if (isset($attributes['slug'])) {
            $slug = $this->uniqueSlug((string) $attributes['slug'], $id);
            if ($slug !== $series->slug) {
                $this->recordAlias($id, $series->slug);
                $fields['slug'] = $slug;
            }
        }

        if (array_key_exists('description', $attributes)) {
            $fields['description'] = $attributes['description'];
        }

        if (array_key_exists('category_id', $attributes)) {
            $fields['category_id'] = $this->categoryId($attributes['category_id']);
        }

        foreach (['is_published', 'member_only', 'hidden', 'featured', 'sequential'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = (int) (bool) $attributes[$key];
            }
        }

        if ($fields === []) {
            return $series;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('series', $fields, ['id' => $id]);

        return $this->find($id) ?? $series;
    }

    /**
     * Delete a series without deleting its videos.
     *
     * The foreign key sets series_id to NULL, so the videos survive and simply
     * stop being part of a sequence. Deleting content because its grouping was
     * removed would be an unrecoverable surprise.
     */
    public function delete(int $id): void
    {
        // {taggables} is polymorphic, so it carries no foreign key and no
        // cascade. See VideoRepository::forceDelete().
        $this->db->execute(
            'DELETE FROM {taggables} WHERE taggable_type = ? AND taggable_id = ?',
            ['series', $id]
        );
        $this->db->execute('DELETE FROM {series} WHERE id = ?', [$id]);
        $this->db->execute(
            'DELETE FROM {slug_aliases} WHERE target_type = "series" AND target_id = ?',
            [$id]
        );
    }

    // --------------------------------------------------------------- ordering

    /**
     * Set the videos in a series, in the given order.
     *
     * @param list<int> $orderedVideoIds
     */
    public function setVideos(int $seriesId, array $orderedVideoIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $orderedVideoIds)));

        $this->db->transaction(function () use ($seriesId, $ids): void {
            // Detach everything first, so a video removed from the form is
            // actually removed rather than silently left behind at its old
            // position.
            $this->db->execute(
                'UPDATE {videos} SET series_id = NULL, series_position = 0 WHERE series_id = ?',
                [$seriesId]
            );

            $position = 0;
            foreach ($ids as $videoId) {
                if ($videoId <= 0) {
                    continue;
                }
                $this->db->execute(
                    'UPDATE {videos} SET series_id = ?, series_position = ? WHERE id = ?',
                    [$seriesId, $position, $videoId]
                );
                $position++;
            }
        });
    }

    /**
     * Move one video up or down within its series.
     *
     * Swapping with the neighbour rather than renumbering the whole run: it is
     * two writes regardless of series length, and it cannot corrupt the order
     * of anything the admin was not looking at.
     */
    public function move(int $videoId, int $direction): void
    {
        $video = $this->db->first(
            'SELECT id, series_id, series_position FROM {videos} WHERE id = ? AND deleted_at IS NULL',
            [$videoId]
        );

        if ($video === null || $video['series_id'] === null) {
            return;
        }

        $comparison = $direction < 0 ? '<' : '>';
        $order = $direction < 0 ? 'DESC' : 'ASC';

        $neighbour = $this->db->first(
            "SELECT id, series_position FROM {videos}
              WHERE series_id = ? AND deleted_at IS NULL AND series_position {$comparison} ?
              ORDER BY series_position {$order} LIMIT 1",
            [(int) $video['series_id'], (int) $video['series_position']]
        );

        if ($neighbour === null) {
            return; // Already at the end.
        }

        $this->db->transaction(function () use ($video, $neighbour): void {
            $this->db->execute(
                'UPDATE {videos} SET series_position = ? WHERE id = ?',
                [(int) $neighbour['series_position'], (int) $video['id']]
            );
            $this->db->execute(
                'UPDATE {videos} SET series_position = ? WHERE id = ?',
                [(int) $video['series_position'], (int) $neighbour['id']]
            );
        });
    }

    // ------------------------------------------------------------- internals

    public function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired);
        if ($base === '') {
            $base = 'series';
        }

        $slug = $base;
        $suffix = 1;

        while (true) {
            $sql = 'SELECT id FROM {series} WHERE slug = ?';
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
             VALUES ("series", ?, ?, NOW())',
            [$id, $oldSlug]
        );
    }

    private function categoryId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function nextPosition(): int
    {
        $max = $this->db->value('SELECT MAX(position) FROM {series}');
        return $max === null ? 0 : ((int) $max) + 1;
    }
}
