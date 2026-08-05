<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * The rows on the homepage, and what each one resolves to.
 *
 * Storage is trivial; the interesting part is resolve(), which turns a row into
 * actual videos and — importantly — drops a row that no longer has anything to
 * point at. A homepage with an empty heading where a deleted playlist used to
 * be is worse than one row shorter.
 */
final class HomeRowRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly VideoRepository $videos,
        private readonly CategoryRepository $categories,
        private readonly SeriesRepository $series,
        private readonly PlaylistRepository $playlists,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /** @return list<HomeRow> */
    public function all(bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : ' WHERE is_active = 1';

        $rows = $this->db->all("SELECT * FROM {home_rows}{$where} ORDER BY position, id");

        return array_map(static fn (array $row): HomeRow => HomeRow::fromRow($row), $rows);
    }

    public function find(int $id): ?HomeRow
    {
        $row = $this->db->first('SELECT * FROM {home_rows} WHERE id = ?', [$id]);

        return $row === null ? null : HomeRow::fromRow($row);
    }

    /** Has anybody configured a homepage at all? */
    public function isConfigured(): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {home_rows} WHERE is_active = 1') > 0;
    }

    /**
     * Turn one row into a heading, a link, and some videos.
     *
     * Returns null when the row has nothing to show — a deleted target, or a
     * source that is genuinely empty for this visitor. The caller drops it
     * rather than rendering a heading over nothing.
     *
     * @param  array<string, mixed> $filters the caller's visibility filters
     * @return array{title: string, url: ?string, videos: list<Video>}|null
     */
    public function resolve(HomeRow $row, array $filters): ?array
    {
        $limit = max(1, min(50, $row->maxItems));

        [$title, $url, $videos] = match ($row->sourceType) {
            HomeRow::FEATURED => [
                'Featured',
                null,
                $this->videos->query($filters + ['featured' => true], 1, $limit)['items'],
            ],
            HomeRow::CATEGORY => $this->fromCategory($row, $filters, $limit),
            HomeRow::SERIES   => $this->fromSeries($row, $limit),
            HomeRow::PLAYLIST => $this->fromPlaylist($row, $filters, $limit),
            // Continue-watching is assembled by the controller, which is the
            // only thing that knows who is asking. An empty list here is the
            // signal for it to fill in.
            HomeRow::CONTINUE => ['Continue watching', null, []],
            default           => [
                'Latest',
                null,
                $this->videos->query($filters, 1, $limit)['items'],
            ],
        };

        // A deleted target.
        if ($title === null) {
            return null;
        }

        if ($videos === [] && $row->sourceType !== HomeRow::CONTINUE) {
            return null;
        }

        return [
            // The editor's title wins; otherwise the source names itself, which
            // is what somebody who left the field blank meant.
            'title'  => $row->title !== '' ? $row->title : $title,
            'url'    => $url,
            'videos' => $videos,
        ];
    }

    /**
     * @param  array<string, mixed> $filters
     * @return array{0: ?string, 1: ?string, 2: list<Video>}
     */
    private function fromCategory(HomeRow $row, array $filters, int $limit): array
    {
        $category = $row->sourceId === null ? null : $this->categories->find($row->sourceId);
        if ($category === null) {
            return [null, null, []];
        }

        return [
            $category->name,
            $category->url(),
            $this->videos->query($filters + ['categoryId' => $category->id], 1, $limit)['items'],
        ];
    }

    /** @return array{0: ?string, 1: ?string, 2: list<Video>} */
    private function fromSeries(HomeRow $row, int $limit): array
    {
        $series = $row->sourceId === null ? null : $this->series->find($row->sourceId);
        if ($series === null) {
            return [null, null, []];
        }

        // In running order, which is the whole reason to put a series on a
        // homepage rather than letting its episodes appear under "latest".
        return [$series->title, $series->url(), array_slice($this->videos->forSeries($series->id), 0, $limit)];
    }

    /**
     * @param  array<string, mixed> $filters
     * @return array{0: ?string, 1: ?string, 2: list<Video>}
     */
    private function fromPlaylist(HomeRow $row, array $filters, int $limit): array
    {
        $playlist = $row->sourceId === null ? null : $this->playlists->find($row->sourceId);
        if ($playlist === null) {
            return [null, null, []];
        }

        $videos = $this->playlists->videos(
            $playlist->id,
            !empty($filters['includeUnpublished']),
            !empty($filters['includeMemberOnly'])
        );

        return [$playlist->title, $playlist->url(), array_slice($videos, 0, $limit)];
    }

    // ----------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): HomeRow
    {
        $source = HomeRow::sanitizeSource($attributes['source_type'] ?? null);
        if ($source === null) {
            throw HttpException::badRequest('Choose what the row should show.');
        }

        $sourceId = $this->targetFor($source, $attributes['source_id'] ?? null);
        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('home_rows', [
            'title'       => trim((string) ($attributes['title'] ?? '')),
            'source_type' => $source,
            'source_id'   => $sourceId,
            'max_items'   => $this->clampItems($attributes['max_items'] ?? 12),
            'position'    => $this->nextPosition(),
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('The row vanished immediately after being created.');
        }

        return $row;
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): HomeRow
    {
        $row = $this->find($id);
        if ($row === null) {
            throw HttpException::notFound('That row does not exist.');
        }

        $fields = [];

        if (array_key_exists('title', $attributes)) {
            $fields['title'] = trim((string) $attributes['title']);
        }

        if (array_key_exists('source_type', $attributes)) {
            $source = HomeRow::sanitizeSource($attributes['source_type']);
            if ($source === null) {
                throw HttpException::badRequest('That is not something a row can show.');
            }
            $fields['source_type'] = $source;
            // Re-resolved against the NEW source: a target that made sense for
            // a series is meaningless once the row points at a playlist, and
            // keeping the old number would silently show the wrong thing.
            $fields['source_id'] = $this->targetFor($source, $attributes['source_id'] ?? null);
        }

        if (array_key_exists('max_items', $attributes)) {
            $fields['max_items'] = $this->clampItems($attributes['max_items']);
        }

        if (array_key_exists('is_active', $attributes)) {
            $fields['is_active'] = (int) (bool) $attributes['is_active'];
        }

        if ($fields === []) {
            return $row;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('home_rows', $fields, ['id' => $id]);

        return $this->find($id) ?? $row;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {home_rows} WHERE id = ?', [$id]);
    }

    /**
     * Move a row up or down.
     *
     * Swapping with the neighbour, the same as everywhere else here — two
     * writes regardless of length, and it cannot disturb anything the editor
     * was not looking at.
     */
    public function move(int $id, int $direction): void
    {
        $row = $this->db->first('SELECT id, position FROM {home_rows} WHERE id = ?', [$id]);
        if ($row === null) {
            return;
        }

        $comparison = $direction < 0 ? '<' : '>';
        $order = $direction < 0 ? 'DESC' : 'ASC';

        $neighbour = $this->db->first(
            "SELECT id, position FROM {home_rows}
              WHERE position {$comparison} ?
              ORDER BY position {$order}, id {$order} LIMIT 1",
            [(int) $row['position']]
        );

        if ($neighbour === null) {
            return;
        }

        $this->db->transaction(function () use ($row, $neighbour): void {
            $this->db->execute(
                'UPDATE {home_rows} SET position = ? WHERE id = ?',
                [(int) $neighbour['position'], (int) $row['id']]
            );
            $this->db->execute(
                'UPDATE {home_rows} SET position = ? WHERE id = ?',
                [(int) $row['position'], (int) $neighbour['id']]
            );
        });
    }

    // ------------------------------------------------------------- internals

    /**
     * The id a source needs, or null for the ones that need none.
     *
     * Refuses a source that needs a target and was not given one — a category
     * row pointing nowhere would render as an empty heading, and the editor
     * would have no way to tell it apart from a category with no videos.
     */
    private function targetFor(string $source, mixed $raw): ?int
    {
        if (!HomeRow::needsTarget($source)) {
            return null;
        }

        $id = (int) $raw;
        if ($id <= 0) {
            throw HttpException::badRequest('Choose which one the row should show.');
        }

        return $id;
    }

    private function clampItems(mixed $raw): int
    {
        return max(1, min(50, (int) $raw));
    }

    private function nextPosition(): int
    {
        $max = $this->db->value('SELECT MAX(position) FROM {home_rows}');

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
